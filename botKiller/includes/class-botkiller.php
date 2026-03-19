<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class BotKiller {
    private $upload_dir;
    private $log_file;
    private $block_file;
    private $block_meta_file;
    private $custom_block_file;
    private $custom_white_file;
    private $timezone;
    private $timezone_offset;
    private $google_ips = [];
    private $bing_ips = [];
    private $cloudflare_ips = [];
    private $max_log_size = 10485760;
    private $blocklist_cache = null;
    private $blocklist_cache_time = 0;
    private $cache_ttl = 300;
    private $search_engine_ips_loaded = false;

public function __construct() {   
    static $instance_loaded = false;
    if ($instance_loaded) {
        return;
    }
     
    $this->setup_secure_directory();
    
    $saved_size_mb = get_option('bot_killer_max_log_size', 10);
    $this->max_log_size = $saved_size_mb * 1024 * 1024;
    
    $this->timezone_offset = get_option('bot_killer_timezone', '+02:00');
    $this->set_timezone();
    
    // Load IP ranges once
    $this->load_search_engine_ips();
    $this->load_cloudflare_ips();

    add_action('init', array($this, 'check_if_blocked'), 1);
    add_filter('woocommerce_add_to_cart_validation', array($this, 'track_and_block'), 1, 3);
    add_action('wp_head', array($this, 'add_no_js_check'), 0);
    add_action('wp_footer', array($this, 'add_js_detection'), 100);
    add_action('admin_menu', array($this, 'admin_menu'));
    add_action('admin_notices', array($this, 'admin_notices'));
    add_action('admin_init', array($this, 'register_settings'));
    add_action('bot_killer_cleanup_expired_blocks', array($this, 'cleanup_expired_blocks'));
    add_action('bot_killer_update_cloudflare_ips', array($this, 'update_cloudflare_ips'));
    add_action('bot_killer_update_bot_ips', array($this, 'update_all_bot_ip_ranges'));
    add_action('wp_ajax_bot_killer_js_detected', array($this, 'handle_js_detection'));
    add_action('wp_ajax_nopriv_bot_killer_js_detected', array($this, 'handle_js_detection'));
    add_action('wp_ajax_bot_killer_update_cloudflare', array($this, 'ajax_update_cloudflare'));
    add_action('wp_ajax_bot_killer_update_all_bots', array($this, 'ajax_update_all_bots'));
    register_deactivation_hook(BOTKILLER_PLUGIN_FILE, array($this, 'deactivate'));
    add_action('wp_ajax_bot_killer_update_search_engines', array($this, 'ajax_update_search_engines'));
    add_action('woocommerce_thankyou', array($this, 'track_order'), 10, 1);
    add_action('woocommerce_order_status_completed', array($this, 'track_order'), 10, 1);
    add_action('woocommerce_cart_item_removed', array($this, 'track_remove_from_cart'), 10, 2);
    add_action('wp_ajax_bot_killer_update_tor_nodes', array($this, 'ajax_update_tor_nodes'));
    add_action('bot_killer_update_cloudflare_ips', array($this, 'update_cloudflare_ips'));
    
    // Schedule events
    if (!wp_next_scheduled('bot_killer_update_tor_nodes')) {
        //wp_schedule_event(time(), 'hourly', 'bot_killer_update_tor_nodes');
        wp_schedule_event(time(), 'twicedaily', 'bot_killer_update_tor_nodes');
    }
    add_action('bot_killer_update_tor_nodes', array($this, 'update_tor_exit_nodes'));
    
    if (!wp_next_scheduled('bot_killer_update_bot_ips')) {
        wp_schedule_event(time(), 'weekly', 'bot_killer_update_bot_ips');
    }
    add_action('bot_killer_update_bot_ips', array($this, 'update_all_bot_ip_ranges'));
    
    if (!wp_next_scheduled('bot_killer_cleanup_expired_blocks')) {
        wp_schedule_event(time(), 'hourly', 'bot_killer_cleanup_expired_blocks');
    }
    if (!wp_next_scheduled('bot_killer_update_cloudflare_ips')) {
        wp_schedule_event(time(), 'weekly', 'bot_killer_update_cloudflare_ips');
    }
    
    $this->auto_whitelist_server_ip();
    
    $instance_loaded = true;
}

    private function setup_secure_directory() {
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/bot-killer/';
        
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
        
        // Only set permissions if needed
        if (is_writable($this->upload_dir) && substr(sprintf('%o', fileperms($this->upload_dir)), -4) !== '0755') {
            chmod($this->upload_dir, 0755);
        }
        
        $htaccess_file = $this->upload_dir . '.htaccess';
        if (!file_exists($htaccess_file)) {
            $htaccess_content = "# Deny access to all files\n<Files *>\nOrder Deny,Allow\nDeny from all\n</Files>\n<FilesMatch \"^(blocked-ips\.txt|blocked-ips-meta\.json|custom-blocked-ips\.txt|custom-whitelist-ips\.txt|bot-killer-log\.txt)$\">\nOrder Allow,Deny\nAllow from 127.0.0.1\nAllow from ::1\n</FilesMatch>";
            file_put_contents($htaccess_file, $htaccess_content, LOCK_EX);
            chmod($htaccess_file, 0644);
        }
        
        $index_file = $this->upload_dir . 'index.php';
        if (!file_exists($index_file)) {
            file_put_contents($index_file, '<?php // Silence is golden', LOCK_EX);
            chmod($index_file, 0644);
        }
        
        $this->log_file = $this->upload_dir . 'bot-killer-log.txt';
        $this->block_file = $this->upload_dir . 'blocked-ips.txt';
        $this->block_meta_file = $this->upload_dir . 'blocked-ips-meta.json';
        $this->custom_block_file = $this->upload_dir . 'custom-blocked-ips.txt';
        $this->custom_white_file = $this->upload_dir . 'custom-whitelist-ips.txt';
        
        $this->create_files();
    }

    private function auto_whitelist_server_ip() {
        if (defined('WP_CLUSTER') && WP_CLUSTER) {
            return;
        }
        
        $server_ip = $_SERVER['SERVER_ADDR'] ?? '';
        if (empty($server_ip)) return;
        
        if ($this->is_ip_in_custom_whitelist($server_ip)) return;
        
        if (file_exists($this->custom_white_file)) {
            $content = file_get_contents($this->custom_white_file);
            if (strpos($content, $server_ip) === false) {
                $comment = "\n# " . __('Auto-whitelisted server IP', 'bot-killer') . " - " . current_time('mysql') . "\n";
                file_put_contents($this->custom_white_file, $comment . $server_ip . "\n", FILE_APPEND | LOCK_EX);
            }
        }
    }

private function load_search_engine_ips() {
    if ($this->search_engine_ips_loaded) {
        return;
    }
    
    $this->google_ips = get_transient('bot_killer_google_ips_v2');
    $this->bing_ips = get_transient('bot_killer_bing_ips_v2');
    
    if (false === $this->google_ips) {
        $this->google_ips = $this->fetch_google_ips('cron');
        set_transient('bot_killer_google_ips_v2', $this->google_ips, WEEK_IN_SECONDS);
    }
    
    if (false === $this->bing_ips) {
        $this->bing_ips = $this->fetch_bing_ips('cron');
        set_transient('bot_killer_bing_ips_v2', $this->bing_ips, WEEK_IN_SECONDS);
    }
    
    $this->search_engine_ips_loaded = true;
}

private function load_cloudflare_ips() {
    $this->cloudflare_ips = get_transient('bot_killer_cloudflare_ips');
    if (false === $this->cloudflare_ips) {
        $this->cloudflare_ips = ['v4' => [], 'v6' => []];
    }
}

public function update_cloudflare_ips($source = 'cron') {
    $ips = ['v4' => [], 'v6' => []];
    $success = false;
    
    // Fetch IPv4 ranges
    $response = wp_remote_get('https://www.cloudflare.com/ips-v4', array(
        'timeout' => 15,
        'sslverify' => true,
        'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
    ));
    
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = trim(wp_remote_retrieve_body($response));
        if (!empty($body)) {
            $ips['v4'] = array_filter(explode("\n", $body));
            $success = true;
        }
    }
    
    // Fetch IPv6 ranges
    $response = wp_remote_get('https://www.cloudflare.com/ips-v6', array(
        'timeout' => 15,
        'sslverify' => true,
        'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
    ));
    
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $body = trim(wp_remote_retrieve_body($response));
        if (!empty($body)) {
            $ips['v6'] = array_filter(explode("\n", $body));
            $success = true;
        }
    }
    
    // If fetch failed, use fallback
    if (empty($ips['v4']) && empty($ips['v6'])) {
        $ips['v4'] = [
            '103.21.244.0/22', '104.16.0.0/12', '108.162.192.0/18', '141.101.64.0/18',
            '162.158.0.0/15', '172.64.0.0/13', '173.245.48.0/20', '188.114.96.0/20',
            '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17'
        ];
        $ips['v6'] = [
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'
        ];
        
        $this->log_action('SYSTEM', __('Using fallback Cloudflare IPs - fetch failed', 'bot-killer'));
        $success = true;
    } else {
        $v4_count = count($ips['v4']);
        $v6_count = count($ips['v6']);
        
        // Log based on source
        if ($source === 'manual') {
            $this->log_action('SYSTEM', sprintf(
                __('Cloudflare IPs manually updated: %d IPv4, %d IPv6 ranges', 'bot-killer'),
                $v4_count,
                $v6_count
            ));
        } else {
            $this->log_action('SYSTEM', sprintf(
                __('Cloudflare IPs updated via cron: %d IPv4, %d IPv6 ranges', 'bot-killer'),
                $v4_count,
                $v6_count
            ));
        }
    }
    
    $this->cloudflare_ips = $ips;
    set_transient('bot_killer_cloudflare_ips', $ips, WEEK_IN_SECONDS);
    
    return $success;
}


public function ajax_update_cloudflare() {
    // Verify nonce and capabilities
    if (!check_ajax_referer('bot_killer_ajax', 'nonce', false)) {
        wp_send_json_error(__('Invalid security token. Please refresh the page and try again.', 'bot-killer'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'bot-killer'));
    }
    
    // Pass 'manual' as the source
    $result = $this->update_cloudflare_ips('manual');
    
    if ($result) {
        $v4_count = count($this->cloudflare_ips['v4']);
        $v6_count = count($this->cloudflare_ips['v6']);
        
        wp_send_json_success(sprintf(
            __('Cloudflare IPs updated! %d IPv4, %d IPv6 ranges', 'bot-killer'),
            $v4_count,
            $v6_count
        ));
    } else {
        wp_send_json_error(__('Failed to update Cloudflare IPs. Check error log for details.', 'bot-killer'));
    }
}
    
public function ajax_update_tor_nodes() {
    // Verify nonce and capabilities
    if (!check_ajax_referer('bot_killer_ajax', 'nonce', false)) {
        wp_send_json_error('Invalid security token. Please refresh the page and try again.');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('You do not have permission to perform this action.');
    }
    
    // Pass 'manual' as the source
    $result = $this->update_tor_exit_nodes('manual');
    
    if ($result) {
        $tor_ips = get_transient('bot_killer_tor_nodes');
        $count = is_array($tor_ips) ? count($tor_ips) : 0;
        
        wp_send_json_success([
            'message' => sprintf('Tor exit nodes updated! %d IPs loaded', $count),
            'count' => $count
        ]);
    } else {
        wp_send_json_error('Failed to update Tor exit nodes');
    }
}

public function ajax_update_search_engines() {
    // Verify nonce and capabilities
    if (!check_ajax_referer('bot_killer_ajax', 'nonce', false)) {
        wp_send_json_error(__('Invalid security token. Please refresh the page and try again.', 'bot-killer'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'bot-killer'));
    }
    
    // Pass 'manual' as the source
    $this->google_ips = $this->fetch_google_ips('manual');
    $this->bing_ips = $this->fetch_bing_ips('manual');
    
    set_transient('bot_killer_google_ips_v2', $this->google_ips, WEEK_IN_SECONDS);
    set_transient('bot_killer_bing_ips_v2', $this->bing_ips, WEEK_IN_SECONDS);
    
    $new_google_count = count($this->google_ips);
    $new_bing_count = count($this->bing_ips);
    
    wp_send_json_success(array(
        'message' => sprintf(
            __('Google/Bing IPs updated! (Google: %d, Bing: %d)', 'bot-killer'),
            $new_google_count,
            $new_bing_count
        ),
        'google_count' => $new_google_count,
        'bing_count' => $new_bing_count
    ));
}

public function ajax_update_all_bots() {
    // Verify nonce and capabilities
    if (!check_ajax_referer('bot_killer_ajax', 'nonce', false)) {
        wp_send_json_error(__('Invalid security token. Please refresh the page and try again.', 'bot-killer'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'bot-killer'));
    }
    
    // Run the update with 'manual' source
    $this->update_all_bot_ip_ranges('manual');
    
    // Store last update time
    update_option('bot_killer_last_bot_update', time());
    
    wp_send_json_success(__('All bot IP ranges updated successfully!', 'bot-killer'));
}

private function fetch_google_ips($source = 'cron') {
    $ips = [];
    
    $urls = [
        'https://developers.google.com/static/search/apis/ipranges/googlebot.json',
        'https://developers.google.com/static/search/apis/ipranges/common-crawlers.json',
    ];
    
    foreach ($urls as $url) {
        $response = wp_remote_get($url, array(
            'timeout'    => 10,
            'sslverify'  => true,
            'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($data['prefixes']) && is_array($data['prefixes'])) {
                foreach ($data['prefixes'] as $prefix) {
                    if (isset($prefix['ipv4Prefix'])) {
                        $ips[] = $prefix['ipv4Prefix'];
                    }
                    if (isset($prefix['ipv6Prefix'])) {
                        $ips[] = $prefix['ipv6Prefix'];
                    }
                }

                if (!empty($ips)) {
                    break;
                }
            }
        }
    }

    $unique_ips = array_values(array_unique($ips));
    
    // Log based on source
    if (!empty($unique_ips)) {
        if ($source === 'manual') {
            $this->log_action('SYSTEM', sprintf(
                __('Google IP ranges manually updated: %d ranges', 'bot-killer'),
                count($unique_ips)
            ));
        } else {
            $this->log_action('SYSTEM', sprintf(
                __('Google IP ranges updated via cron: %d ranges', 'bot-killer'),
                count($unique_ips)
            ));
        }
    } else {
        $this->log_action('SYSTEM', __('Google IP ranges update failed - using fallback', 'bot-killer'));
    }
    
    return $unique_ips;
}

private function fetch_bing_ips($source = 'cron') {
    $ips = [];
    $response = wp_remote_get('https://www.bing.com/toolbox/bingbot.json', array(
        'timeout' => 10, 
        'sslverify' => true, 
        'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
    ));
    
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['prefixes']) && is_array($data['prefixes'])) {
            foreach ($data['prefixes'] as $prefix) {
                if (isset($prefix['ipv4Prefix'])) $ips[] = $prefix['ipv4Prefix'];
                if (isset($prefix['ipv6Prefix'])) $ips[] = $prefix['ipv6Prefix'];
            }
        }
    }
    
    if (empty($ips)) {
        // Fallback Bing IPs
        $ips = ['13.64.0.0/16', '13.65.0.0/16', '13.66.0.0/16', '13.67.0.0/16', '13.68.0.0/16', 
                '13.69.0.0/16', '13.70.0.0/16', '13.71.0.0/16', '13.72.0.0/16', '13.73.0.0/16', 
                '13.74.0.0/16', '13.75.0.0/16', '13.76.0.0/16', '13.77.0.0/16', '13.78.0.0/16', 
                '13.79.0.0/16', '13.80.0.0/16', '13.81.0.0/16', '13.82.0.0/16', '13.83.0.0/16', 
                '13.84.0.0/16', '13.85.0.0/16', '13.86.0.0/16', '13.87.0.0/16', '13.88.0.0/16', 
                '13.89.0.0/16', '13.90.0.0/16', '13.91.0.0/16', '13.92.0.0/16', '13.93.0.0/16', 
                '13.94.0.0/16', '13.95.0.0/16', '20.0.0.0/8', '40.0.0.0/8', '40.77.0.0/16', 
                '52.0.0.0/8', '65.55.0.0/16', '131.253.0.0/16', '157.55.0.0/16', '207.46.0.0/16', 
                '2620:1ec::/32', '2a01:111::/32', '2001:4898::/32'];
        
        $this->log_action('SYSTEM', __('Bing IP ranges update failed - using fallback', 'bot-killer'));
    } else {
        // Log based on source
        if ($source === 'manual') {
            $this->log_action('SYSTEM', sprintf(
                __('Bing IP ranges manually updated: %d ranges', 'bot-killer'),
                count($ips)
            ));
        } else {
            $this->log_action('SYSTEM', sprintf(
                __('Bing IP ranges updated via cron: %d ranges', 'bot-killer'),
                count($ips)
            ));
        }
    }
    
    return $ips;
}

    private function reverse_dns_lookup($ip, $timeout = 5) {
        $original_timeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', $timeout);
        
        $hostname = @gethostbyaddr($ip);
        
        ini_set('default_socket_timeout', $original_timeout);
        
        return ($hostname !== false && $hostname !== $ip) ? $hostname : false;
    }

    private function forward_dns_lookup($hostname, $timeout = 5) {
        $original_timeout = ini_get('default_socket_timeout');
        ini_set('default_socket_timeout', $timeout);
        
        $ips = @gethostbynamel($hostname);
        
        ini_set('default_socket_timeout', $original_timeout);
        
        return $ips;
    }

private function get_cart_interacting_bot($ip, $user_agent) {
    // ========== CHECK IF IP IS ALREADY BLOCKED ==========
    if ($this->is_ip_blocked($ip)) {
        return false; // IP already banned, don't waste resources
    }
    // =====================================================
    
    $cache_key = 'bot_killer_bot_type_' . md5($ip . $user_agent);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }
    
    $cart_bots = [
        // =============================================
        // SEARCH ENGINES - STRICT CRAWLERS
        // =============================================
        'google' => [
            'agents' => ['Googlebot', 'Googlebot-Image', 'Googlebot-News', 'Googlebot-Video', 
                        'Googlebot-Mobile', 'AdsBot-Google', 'Mediapartners-Google', 
                        'APIs-Google', 'DuplexWeb-Google', 'FeedFetcher-Google',
                        'Google-Read-Aloud', 'Google-Site-Verification', 'Google-PageRenderer',
                        'Googlebot/2.1', 'Googlebot/2.2'],
            'dns' => ['.googlebot.com', '.google.com'],
            'ip_ranges' => $this->google_ips,
            'asn' => ['15169'],
            'type' => 'strict',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'high',
            'description' => 'Google bot — requires googlebot.com domain or Google IP'
        ],
        'bing' => [
            'agents' => ['bingbot', 'BingPreview', 'msnbot', 'msnbot-media', 
                        'adidxbot', 'BingBot', 'BingMobile'],
            'dns' => ['.search.msn.com'],
            'ip_ranges' => $this->bing_ips,
            'asn' => ['8068', '8069', '8070', '8071', '8072', '8073', '8074', '8075'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'high',
            'description' => 'Bing bot — requires search.msn.com domain or Microsoft IP'
        ],
        'baidu' => [
            'agents' => ['Baiduspider', 'Baiduspider-image', 'Baiduspider-video', 'Baiduspider-news'],
            'ip_ranges' => $this->get_baidu_ip_ranges(),
            'asn' => ['55967', '37965'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'Baidu bot — requires official IP ranges'
        ],
        'yandex' => [
            'agents' => ['YandexBot', 'YandexImages', 'YandexVideo', 'YandexNews', 'YandexMobileBot'],
            'dns' => ['.yandex.ru', '.yandex.net'],
            'ip_ranges' => $this->get_yandex_ip_ranges(),
            'asn' => ['13238', '208722'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'Yandex bot — requires DNS or IP verification'
        ],
        'duckduckgo' => [
            'agents' => ['DuckDuckBot', 'DuckDuckGo-Favicons-Bot'],
            'ip_ranges' => $this->get_duckduckgo_ip_ranges(),
            'asn' => ['42729'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'DuckDuckGo bot — requires official IP ranges'
        ],
        'seznam' => [
            'agents' => ['SeznamBot'],
            'ip_ranges' => $this->get_seznam_ip_ranges(),
            'asn' => ['43037'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'Seznam search crawler'
        ],
        'petalbot' => [
            'agents' => ['PetalBot'],
            'ip_ranges' => $this->get_petalbot_ip_ranges(),
            'asn' => ['136907'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'Huawei Petal search crawler'
        ],

        // =============================================
        // SOCIAL PREVIEW BOTS
        // =============================================
        'facebook' => [
            'agents' => ['facebookexternalhit', 'meta-externalagent', 'meta-webindexer', 
                        'meta-externalads', 'meta-externalfetcher', 'Facebot'],
            'ip_ranges' => $this->get_facebook_ip_ranges(),
            'asn' => ['32934', '63293'],
            'type' => 'social',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'high',
            'description' => 'Facebook bot — requires AS32934 or AS63293'
        ],
        'whatsapp' => [
            'agents' => ['WhatsApp'],
            'ip_ranges' => $this->get_facebook_ip_ranges(),
            'asn' => ['32934'],
            'type' => 'social',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'WhatsApp link preview bot'
        ],
        'linkedin' => [
            'agents' => ['LinkedInBot'],
            'ip_ranges' => $this->get_linkedin_ip_ranges(),
            'asn' => ['14413'],
            'type' => 'social',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'LinkedIn bot — requires AS14413'
        ],
        'pinterest' => [
            'agents' => ['Pinterestbot'],
            'ip_ranges' => $this->get_pinterest_ip_ranges(),
            'asn' => ['40027'],
            'type' => 'social',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'Pinterest bot — requires official IP ranges'
        ],
        'twitter' => [
            'agents' => ['Twitterbot'],
            'ip_ranges' => $this->get_twitter_ip_ranges(),
            'asn' => ['13414'],
            'type' => 'social',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'Twitter bot — requires AS13414'
        ],
        'discord' => [
            'agents' => ['Discordbot'],
            'ip_ranges' => $this->get_discord_ip_ranges(),
            'asn' => ['46475', '36978'],
            'type' => 'social',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'Discord bot — requires official IP ranges'
        ],
        'slack' => [
            'agents' => ['Slackbot-LinkExpanding', 'Slack-ImgProxy'],
            'ip_ranges' => $this->get_slack_ip_ranges(),
            'asn' => [],
            'type' => 'social',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'Slack bot — requires official IP ranges'
        ],
        'telegram' => [
            'agents' => ['TelegramBot', 'TelegramBot (like TwitterBot)'],
            'ip_ranges' => $this->get_telegram_ip_ranges(),
            'asn' => [],
            'type' => 'social',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'Telegram bot — requires official IP ranges'
        ],
        'microsoftpreview' => [
            'agents' => ['SkypeUriPreview', 'TeamsBot'],
            'ip_ranges' => $this->bing_ips,
            'asn' => ['8075'],
            'type' => 'social',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'Microsoft link preview bots'
        ],

        // =============================================
        // AI & HYBRID BOTS
        // =============================================
        'openai' => [
            'agents' => ['OAI-SearchBot', 'ChatGPT-User', 'GPTBot'],
            'ip_ranges' => $this->get_openai_ip_ranges(),
            'asn' => [],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'high',
            'description' => 'OpenAI bot — requires official IP ranges'
        ],
        'anthropic' => [
            'agents' => ['ClaudeBot', 'Claude-SearchBot', 'Claude-User'],
            'ip_ranges' => $this->get_anthropic_ip_ranges(),
            'asn' => [],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'high',
            'description' => 'Claude bot — requires official IP ranges'
        ],
        'perplexity' => [
            'agents' => ['PerplexityBot', 'Perplexity-User'],
            'ip_ranges' => $this->get_perplexity_ip_ranges(),
            'asn' => [],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'high',
            'description' => 'Perplexity bot — requires official IP ranges'
        ],
        'gemini' => [
            'agents' => ['Gemini-Deep-Research'],
            'ip_ranges' => $this->google_ips,
            'asn' => ['15169'],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'high',
            'description' => 'Gemini bot — requires Google IP ranges'
        ],
        'google_ai' => [
            'agents' => ['Google-Extended', 'Google-CloudVertexBot'],
            'dns' => ['.google.com', '.googlebot.com'],
            'ip_ranges' => $this->google_ips,
            'asn' => ['15169'],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'high',
            'description' => 'Google AI crawler (Google-Extended, Vertex AI)'
        ],
        'bytespider' => [
            'agents' => ['Bytespider'],
            'dns' => [],
            'ip_ranges' => $this->get_bytespider_ip_ranges(),
            'asn' => ['37963', '45090'],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'high',
            'description' => 'Bytespider — requires ByteDance ASNs'
        ],
        'tiktok' => [
            'agents' => ['TikTokBot'],
            'ip_ranges' => $this->get_tiktok_ip_ranges(),
            'asn' => ['396982', '45102'],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'TikTok crawler'
        ],
        'mistral' => [
            'agents' => ['MistralAI-User'],
            'dns' => ['.mistral.ai'],
            'ip_ranges' => ['212.115.41.0/24'],
            'asn' => ['212949'],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'Mistral AI — requires AS212949 or official IP'
        ],
        'grok' => [
            'agents' => ['Grok', 'xAI'],
            'dns' => ['.x.ai', '.grok.com'],
            'ip_ranges' => [],
            'asn' => [],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => false,
            'spoof_risk' => 'medium',
            'description' => 'Grok — requires x.ai or grok.com domain'
        ],
        'deepseek' => [
            'agents' => [],
            'dns' => ['.deepseek.com'],
            'ip_ranges' => [],
            'asn' => [],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => false,
            'spoof_risk' => 'low',
            'description' => 'DeepSeek AI'
        ],
        'qwen' => [
            'agents' => ['QwenBot'],
            'dns' => ['.qwenlm.ai', '.aliyun.com'],
            'ip_ranges' => [],
            'asn' => ['37963'],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'Alibaba Qwen AI — requires AS37963'
        ],
        'metaai' => [
            'agents' => ['Meta-ExternalAgent', 'Meta-ExternalFetcher'],
            'ip_ranges' => $this->get_facebook_ip_ranges(),
            'asn' => ['32934'],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'high',
            'description' => 'Meta AI crawler'
        ],

        // =============================================
        // SEO CRAWLERS
        // =============================================
        'ahrefs' => [
            'agents' => ['AhrefsBot', 'AhrefsSiteAudit'],
            'ip_ranges' => $this->get_ahrefs_ip_ranges(),
            'asn' => ['209242'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'Ahrefs crawler — requires official IP ranges'
        ],
        'semrush' => [
            'agents' => ['SemrushBot', 'SemrushBot-SA'],
            'ip_ranges' => $this->get_semrush_ip_ranges(),
            'asn' => ['203726'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'Semrush crawler — requires official IP ranges'
        ],
        'mj12bot' => [
            'agents' => ['MJ12bot'],
            'ip_ranges' => $this->get_mj12_ip_ranges(),
            'asn' => ['204734'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'Majestic crawler'
        ],
        'dotbot' => [
            'agents' => ['DotBot'],
            'ip_ranges' => $this->get_dotbot_ip_ranges(),
            'asn' => ['26347'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'medium',
            'description' => 'Moz crawler'
        ],

        // =============================================
        // CLOUD & OTHER
        // =============================================
        'cloudflare' => [
            'agents' => ['Cloudflare'],
            'ip_ranges' => $this->cloudflare_ips,
            'asn' => ['13335'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'Cloudflare bot — requires AS13335'
        ],
        'ccbot' => [
            'agents' => ['CCBot'],
            'dns' => ['.crawl.commoncrawl.org'],
            'ip_ranges' => $this->get_ccbot_ip_ranges(),
            'asn' => ['16509'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'CCBot — requires crawl.commoncrawl.org domain'
        ],
        'amazonbot' => [
            'agents' => ['Amazonbot'],
            'dns' => ['.crawl.amazonbot.amazon'],
            'ip_ranges' => $this->get_amazonbot_ip_ranges(),
            'asn' => ['16509'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'Amazonbot — requires crawl.amazonbot.amazon domain'
        ],
        'applebot' => [
            'agents' => ['Applebot'],
            'dns' => ['.applebot.apple.com'],
            'ip_ranges' => $this->get_applebot_ip_ranges(),
            'asn' => ['714', '6185'],
            'type' => 'strict',
            'allow_js' => false,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'Applebot — requires applebot.apple.com domain'
        ],
        'youbot' => [
            'agents' => ['YouBot'],
            'dns' => [],
            'ip_ranges' => $this->get_youbot_ip_ranges(),
            'asn' => [],
            'type' => 'hybrid',
            'allow_js' => true,
            'require_verification' => true,
            'spoof_risk' => 'low',
            'description' => 'YouBot AI search crawler'
        ],
    ];
    
    foreach ($cart_bots as $bot_name => $bot_data) {
        foreach ($bot_data['agents'] as $agent) {
            if (stripos($user_agent, $agent) !== false) {
                
                // ========== DNS verification ==========
                if (isset($bot_data['dns']) && !empty($bot_data['dns'])) {
                    $hostname = $this->reverse_dns_lookup($ip, 3);
                    if ($hostname) {
                        foreach ($bot_data['dns'] as $suffix) {
                            if (strpos($hostname, $suffix) !== false) {
                                $forward_ips = $this->forward_dns_lookup($hostname, 3);
                                if ($forward_ips && is_array($forward_ips) && in_array($ip, $forward_ips)) {
                                    $this->log_bot_detection($ip, $bot_name, $agent, 'dns_forward');
                                    set_transient($cache_key, $bot_name, 12 * HOUR_IN_SECONDS);
                                    return $bot_name;
                                } else {
                                    $this->log_action($ip, "Forward DNS verification FAILED for {$hostname} - possible spoof");
                                }
                            }
                        }
                    }
                }
                
                // ========== IP range verification ==========
                if (isset($bot_data['ip_ranges']) && !empty($bot_data['ip_ranges'])) {
                    foreach ($bot_data['ip_ranges'] as $range) {
                        if ($this->ip_in_range($ip, $range)) {
                            $this->log_bot_detection($ip, $bot_name, $agent, 'ip_range');
                            set_transient($cache_key, $bot_name, 6 * HOUR_IN_SECONDS);
                            return $bot_name;
                        }
                    }
                }
                
                // ========== ASN verification ==========
                if (isset($bot_data['asn']) && !empty($bot_data['asn'])) {
                    $asn_info = $this->get_asn_for_ip($ip);
                    if ($asn_info && isset($asn_info['asn']) && in_array($asn_info['asn'], $bot_data['asn'])) {
                        $this->log_bot_detection($ip, $bot_name, $agent, 'asn_match');
                        set_transient($cache_key, $bot_name, 6 * HOUR_IN_SECONDS);
                        return $bot_name;
                    }
                }
                
                // ========== CHECK FOR JS/COOKIES ==========
                $has_js = get_transient('bot_killer_js_detected_' . md5($ip));
                $has_cookies = !empty($_COOKIE);
                
                // For strict bots (except Google) - JS/cookies = SPOOF
                if ($bot_data['type'] === 'strict' && empty($bot_data['allow_js']) && ($has_js || $has_cookies)) {
                    $risk = $bot_data['spoof_risk'] ?? 'medium';
                    $this->log_action($ip, "SPOOF ATTEMPT " . strtoupper($risk) . ": {$bot_name} with JS/cookies");
                    set_transient($cache_key, false, HOUR_IN_SECONDS);
                    
                    // Block only for high risk
                    if ($risk === 'high') {
                        $this->block_ip($ip, "SPOOF ATTEMPT HIGH: {$bot_name}");
                    }
                    return false;
                }
                // ===============================================
                
                if (isset($bot_data['require_verification']) && $bot_data['require_verification']) {
                    $asn_info = $this->get_asn_for_ip($ip);
                    $asn_text = $asn_info ? " (ASN: {$asn_info['asn']})" : '';
                    $risk = $bot_data['spoof_risk'] ?? 'medium';
                    
                    $this->log_action($ip, "SPOOF ATTEMPT " . strtoupper($risk) . ": {$bot_data['description']}{$asn_text}");
                    set_transient($cache_key, false, HOUR_IN_SECONDS);
                    
                    if ($risk === 'high') {
                        $this->block_ip($ip, "SPOOF ATTEMPT HIGH: {$bot_name}");
                    }
                    return false;
                }
                
                // For bots without require_verification - just log
                $this->log_bot_detection($ip, $bot_name, $agent, 'user_agent_only');
                set_transient($cache_key, $bot_name, 2 * HOUR_IN_SECONDS);
                return $bot_name;
            }
        }
    }
    
    return false;
}

// =============================================
// SEO CRAWLERS IP RANGES
// =============================================

private function get_ahrefs_ip_ranges() {
    $cache_key = 'bot_killer_ahrefs_ips';
    $ranges = get_transient($cache_key);
    if ($ranges !== false) return $ranges;
    
    // Ahrefs official IP ranges (AS209242)
    $ranges = [
        '54.66.204.0/24',
        '54.66.205.0/24',
        '54.66.206.0/24',
        '54.66.207.0/24',
        '54.153.26.0/24',
        '54.153.27.0/24',
        '54.153.28.0/24',
        '54.153.29.0/24',
        '54.153.30.0/24',
        '54.153.31.0/24',
        '54.206.48.0/24',
        '54.206.49.0/24',
        '54.206.50.0/24',
        '54.206.51.0/24',
        '54.252.170.0/24',
        '54.252.171.0/24',
        '54.252.172.0/24',
        '54.252.173.0/24'
    ];
    
    set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
    return $ranges;
}

private function get_semrush_ip_ranges() {
    $cache_key = 'bot_killer_semrush_ips';
    $ranges = get_transient($cache_key);
    if ($ranges !== false) return $ranges;
    
    // Semrush official IP ranges (AS203726)
    $ranges = [
        '185.70.184.0/22',
        '185.191.200.0/22',
        '185.209.28.0/22',
        '185.225.16.0/22',
        '193.108.72.0/22',
        '193.108.73.0/24',
        '193.108.74.0/24',
        '193.108.75.0/24',
        '195.54.164.0/22',
        '2a0a:40c0::/32',
        '2a0a:40c1::/32'
    ];
    
    set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
    return $ranges;
}

private function get_mj12_ip_ranges() {
    $cache_key = 'bot_killer_mj12_ips';
    $ranges = get_transient($cache_key);
    if ($ranges !== false) return $ranges;
    
    // Majestic MJ12bot official IP ranges
    $ranges = [
        '213.227.144.0/24',
        '213.227.145.0/24',
        '213.227.146.0/24',
        '213.227.147.0/24',
        '213.227.148.0/24',
        '213.227.149.0/24',
        '213.227.150.0/24',
        '213.227.151.0/24',
        '80.82.64.0/20',
        '80.82.65.0/24',
        '80.82.66.0/24',
        '80.82.67.0/24',
        '80.82.68.0/24',
        '80.82.69.0/24',
        '80.82.70.0/24',
        '80.82.71.0/24',
        '80.82.72.0/24',
        '80.82.73.0/24',
        '80.82.74.0/24',
        '80.82.75.0/24',
        '80.82.76.0/24',
        '80.82.77.0/24',
        '80.82.78.0/24',
        '80.82.79.0/24'
    ];
    
    set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
    return $ranges;
}

private function get_dotbot_ip_ranges() {
    $cache_key = 'bot_killer_dotbot_ips';
    $ranges = get_transient($cache_key);
    if ($ranges !== false) return $ranges;
    
    // DotBot (Moz) uses Google AS15169, but has specific ranges
    $ranges = [
        '35.196.74.192/28',
        '35.196.115.160/27',
        '35.196.117.80/28',
        '35.196.118.144/28',
        '35.196.121.0/24',
        '35.196.125.0/24',
        '35.196.137.0/24',
        '35.196.147.0/24',
        '35.203.210.0/23',
        '35.203.212.0/23',
        '35.203.214.0/23',
        '35.227.70.0/23',
        '35.229.32.0/23',
        '35.229.34.0/23',
        '35.229.36.0/23',
        '35.229.38.0/23',
        '35.229.40.0/23',
        '35.229.42.0/23',
        '35.229.44.0/23',
        '35.229.46.0/23',
        '35.229.48.0/23',
        '35.229.50.0/23',
        '35.229.52.0/23',
        '35.229.54.0/23',
        '35.229.56.0/23',
        '35.229.58.0/23',
        '35.229.60.0/23',
        '35.229.62.0/23',
        '35.236.0.0/17',
        '35.242.0.0/15'
    ];
    
    set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
    return $ranges;
}

// =============================================
// AI CRAWLERS IP RANGES
// =============================================

private function get_tiktok_ip_ranges() {
    $cache_key = 'bot_killer_tiktok_ips';
    $ranges = get_transient($cache_key);
    if ($ranges !== false) return $ranges;
    
    // TikTok/ByteDance IP ranges (AS396982, AS45102)
    $ranges = [
        '23.111.64.0/18',
        '45.114.16.0/20',
        '45.114.16.0/22',
        '45.114.20.0/22',
        '45.114.24.0/22',
        '45.114.28.0/22',
        '45.114.30.0/24',
        '45.114.31.0/24',
        '45.114.32.0/22',
        '45.114.36.0/22',
        '45.114.40.0/22',
        '45.114.44.0/22',
        '45.114.48.0/22',
        '45.114.52.0/22',
        '45.114.56.0/22',
        '45.114.60.0/22',
        '45.114.62.0/24',
        '45.114.63.0/24',
        '103.135.60.0/22',
        '103.135.62.0/24',
        '103.135.63.0/24',
        '149.129.128.0/17',
        '149.129.128.0/18',
        '149.129.192.0/18',
        '149.129.224.0/19',
        '149.129.240.0/20',
        '149.129.248.0/21',
        '149.129.252.0/22',
        '149.129.254.0/23',
        '149.129.255.0/24',
        '161.117.0.0/16',
        '161.117.128.0/17',
        '161.117.192.0/18',
        '161.117.224.0/19',
        '161.117.240.0/20',
        '161.117.248.0/21',
        '161.117.252.0/22',
        '161.117.254.0/23',
        '161.117.255.0/24',
        '163.171.128.0/17',
        '170.179.128.0/17',
        '170.179.192.0/18',
        '170.179.224.0/19',
        '170.179.240.0/20',
        '170.179.248.0/21',
        '170.179.252.0/22',
        '170.179.254.0/23',
        '170.179.255.0/24',
        '182.16.128.0/17',
        '182.16.192.0/18',
        '182.16.224.0/19',
        '182.16.240.0/20',
        '182.16.248.0/21',
        '182.16.252.0/22',
        '182.16.254.0/23',
        '182.16.255.0/24',
        '202.89.96.0/19',
        '202.89.112.0/20',
        '202.89.120.0/21',
        '202.89.124.0/22',
        '202.89.126.0/23',
        '202.89.127.0/24',
        '203.107.0.0/17',
        '203.107.128.0/17',
        '203.107.160.0/19',
        '203.107.192.0/18',
        '203.107.224.0/19',
        '203.107.240.0/20',
        '203.107.248.0/21',
        '203.107.252.0/22',
        '203.107.254.0/23',
        '203.107.255.0/24',
        '208.127.64.0/18',
        '208.127.96.0/19',
        '208.127.112.0/20',
        '208.127.120.0/21',
        '208.127.124.0/22',
        '208.127.126.0/23',
        '208.127.127.0/24'
    ];
    
    set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
    return $ranges;
}

// =============================================
// REGIONAL SEARCH ENGINES IP RANGES
// =============================================

private function get_seznam_ip_ranges() {
    $cache_key = 'bot_killer_seznam_ips';
    $ranges = get_transient($cache_key);
    if ($ranges !== false) return $ranges;
    
    // Seznam.cz IP ranges (AS43037)
    $ranges = [
        '77.75.72.0/21',
        '77.75.72.0/24',
        '77.75.73.0/24',
        '77.75.74.0/24',
        '77.75.75.0/24',
        '77.75.76.0/24',
        '77.75.77.0/24',
        '77.75.78.0/24',
        '77.75.79.0/24',
        '185.41.20.0/22',
        '185.41.20.0/24',
        '185.41.21.0/24',
        '185.41.22.0/24',
        '185.41.23.0/24',
        '193.85.16.0/20',
        '193.85.16.0/24',
        '193.85.17.0/24',
        '193.85.18.0/24',
        '193.85.19.0/24',
        '193.85.20.0/24',
        '193.85.21.0/24',
        '193.85.22.0/24',
        '193.85.23.0/24',
        '193.85.24.0/24',
        '193.85.25.0/24',
        '193.85.26.0/24',
        '193.85.27.0/24',
        '193.85.28.0/24',
        '193.85.29.0/24',
        '193.85.30.0/24',
        '193.85.31.0/24',
        '2a02:598::/32'
    ];
    
    set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
    return $ranges;
}

private function get_petalbot_ip_ranges() {
    $cache_key = 'bot_killer_petalbot_ips';
    $ranges = get_transient($cache_key);
    if ($ranges !== false) return $ranges;
    
    // Huawei PetalBot IP ranges (AS136907)
    $ranges = [
        '103.104.128.0/20',
        '103.104.128.0/24',
        '103.104.129.0/24',
        '103.104.130.0/24',
        '103.104.131.0/24',
        '103.104.132.0/24',
        '103.104.133.0/24',
        '103.104.134.0/24',
        '103.104.135.0/24',
        '103.104.136.0/24',
        '103.104.137.0/24',
        '103.104.138.0/24',
        '103.104.139.0/24',
        '103.104.140.0/24',
        '103.104.141.0/24',
        '103.104.142.0/24',
        '103.104.143.0/24',
        '119.8.0.0/15',
        '119.8.0.0/16',
        '119.9.0.0/16',
        '119.10.0.0/17',
        '119.10.128.0/17',
        '159.138.0.0/15',
        '159.138.0.0/16',
        '159.139.0.0/16'
    ];
    
    set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
    return $ranges;
}


private function log_bot_detection($ip, $bot_name, $agent, $method) {
    $method_text = '';
    
    switch ($method) {
        case 'dns_forward':
            $method_text = 'DNS verified';
            break;
        case 'asn_match':
            $method_text = 'ASN match';
            break;
        case 'ip_range':
            $method_text = 'IP range match';
            break;
        case 'user_agent_only':
            $method_text = 'User-Agent only';
            break;
        default:
            $method_text = 'verified';
    }

    $short_agent = substr($agent, 0, 50);
    if (strlen($agent) > 50) {
        $short_agent .= '...';
    }
    
    $this->log_action($ip, strtoupper($bot_name) . " bot detected - {$short_agent} ({$method_text})");
}

    private function get_facebook_ip_ranges() {
        $cache_key = 'bot_killer_facebook_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '31.13.24.0/21', '31.13.64.0/18', '45.64.40.0/22', '66.220.144.0/20',
            '69.63.176.0/20', '69.171.224.0/19', '74.119.76.0/22', '103.4.96.0/22',
            '129.134.0.0/17', '157.240.0.0/17', '173.252.64.0/18', '179.60.192.0/22',
            '185.60.216.0/22', '204.15.20.0/22'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_openai_ip_ranges() {
        $cache_key = 'bot_killer_openai_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '23.98.142.176/28', '40.126.27.64/26', '52.177.176.0/27',
            '52.184.192.0/26', '52.230.24.0/25', '52.233.106.128/25'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_anthropic_ip_ranges() {
        $cache_key = 'bot_killer_anthropic_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '3.5.140.0/22', '13.248.96.0/24', '15.197.128.0/20',
            '18.160.0.0/15', '18.232.0.0/14', '52.46.0.0/18'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_telegram_ip_ranges() {
        $cache_key = 'bot_killer_telegram_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '91.108.4.0/22', '91.108.8.0/21', '91.108.16.0/21',
            '91.108.56.0/22', '149.154.160.0/20', '185.76.40.0/22'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_perplexity_ip_ranges() {
        $cache_key = 'bot_killer_perplexity_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '34.94.0.0/16', '35.227.0.0/17', '104.154.0.0/15'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_duckduckgo_ip_ranges() {
        $cache_key = 'bot_killer_duckduckgo_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [];
        
        $response = wp_remote_get('https://duckduckgo.com/duckduckbot.json', [
            'timeout' => 10,
            'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
        ]);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($data['addresses']) && is_array($data['addresses'])) {
                $ranges = $data['addresses'];
            }
        }
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_linkedin_ip_ranges() {
        $cache_key = 'bot_killer_linkedin_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '13.107.8.0/24', '13.107.12.0/24', '13.107.42.0/24',
            '13.107.44.0/24', '13.107.64.0/24', '13.107.96.0/24',
            '13.107.128.0/24', '13.107.136.0/24', '13.107.160.0/24',
            '13.107.192.0/24', '13.107.224.0/24', '40.126.0.0/18'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_pinterest_ip_ranges() {
        $cache_key = 'bot_killer_pinterest_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '54.236.1.0/24', '54.236.2.0/23', '54.236.4.0/22',
            '54.236.8.0/21', '54.236.16.0/20', '54.236.32.0/19'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_twitter_ip_ranges() {
        $cache_key = 'bot_killer_twitter_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '199.16.156.0/22', '199.59.148.0/22', '199.96.56.0/21',
            '202.160.128.0/22', '209.237.192.0/19'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_discord_ip_ranges() {
        $cache_key = 'bot_killer_discord_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '35.215.0.0/16', '35.220.0.0/16', '35.221.0.0/16',
            '35.236.0.0/16', '35.242.0.0/16', '35.247.0.0/16'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_slack_ip_ranges() {
        $cache_key = 'bot_killer_slack_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '34.80.0.0/15', '34.96.0.0/14', '34.120.0.0/16',
            '35.186.0.0/16', '35.188.0.0/15', '35.192.0.0/14'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_baidu_ip_ranges() {
        $cache_key = 'bot_killer_baidu_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '14.215.0.0/16', '116.179.0.0/16', '119.63.0.0/16',
            '123.125.0.0/16', '180.76.0.0/16', '220.181.0.0/16'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_yandex_ip_ranges() {
        $cache_key = 'bot_killer_yandex_ips';
        $ranges = get_transient($cache_key);
        
        if ($ranges !== false) {
            return $ranges;
        }
        
        $ranges = [
            '37.9.64.0/18', '77.88.0.0/18', '93.158.128.0/18',
            '95.108.128.0/17', '141.8.128.0/18', '213.180.192.0/19'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_ccbot_ip_ranges() {
        $cache_key = 'bot_killer_ccbot_ips';
        $ranges = get_transient($cache_key);
        if ($ranges !== false) return $ranges;
        
        $response = wp_remote_get('https://index.commoncrawl.org/ccbot.json');
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($data['addresses'])) {
                set_transient($cache_key, $data['addresses'], WEEK_IN_SECONDS);
                return $data['addresses'];
            }
        }
        return [];
    }

    private function get_amazonbot_ip_ranges() {
        $cache_key = 'bot_killer_amazonbot_ips';
        $ranges = get_transient($cache_key);
        if ($ranges !== false) return $ranges;
        
        $response = wp_remote_get('https://developer.amazon.com/amazonbot/ip-addresses.json');
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($data['ip_prefixes'])) {
                set_transient($cache_key, $data['ip_prefixes'], WEEK_IN_SECONDS);
                return $data['ip_prefixes'];
            }
        }
        return [];
    }

    private function get_applebot_ip_ranges() {
        $cache_key = 'bot_killer_applebot_ips';
        $ranges = get_transient($cache_key);
        if ($ranges !== false) return $ranges;
        
        $ranges = [
            '17.0.0.0/8', '163.1.0.0/16', '208.50.0.0/16',
            '209.85.0.0/16', '192.35.50.0/24', '192.35.51.0/24',
            '192.35.52.0/24', '192.35.53.0/24', '192.35.54.0/24',
            '192.35.55.0/24', '192.35.56.0/24', '192.35.57.0/24',
            '192.35.58.0/24', '192.35.59.0/24'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_bytespider_ip_ranges() {
        $cache_key = 'bot_killer_bytespider_ips';
        $ranges = get_transient($cache_key);
        if ($ranges !== false) return $ranges;
        
        $ranges = [
            '47.252.0.0/16', '110.42.0.0/16', '123.126.0.0/16', '140.210.0.0/16',
            '159.27.0.0/16', '165.225.0.0/16', '182.16.0.0/16', '202.89.0.0/16',
            '203.107.0.0/16', '210.209.0.0/16', '218.244.0.0/16', '220.181.0.0/16',
            '35.221.0.0/16', '34.96.0.0/14', '34.120.0.0/16', '45.113.0.0/16',
            '149.129.0.0/16', '161.117.0.0/16', '170.179.0.0/16', '179.61.0.0/16',
            '182.176.0.0/16', '198.11.0.0/16', '199.180.0.0/16'
        ];
        
        set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
        return $ranges;
    }

    private function get_youbot_ip_ranges() {
        $cache_key = 'bot_killer_youbot_ips';
        $ranges = get_transient($cache_key);
        if ($ranges !== false) return $ranges;
        
        set_transient($cache_key, [], WEEK_IN_SECONDS);
        return [];
    }

public function update_all_bot_ip_ranges($source = 'cron') {
    if ($source === 'manual') {
        $this->log_action('SYSTEM', __('Starting manual update of all bot IP ranges...', 'bot-killer'));
    } else {
        $this->log_action('SYSTEM', __('Starting weekly update of all bot IP ranges...', 'bot-killer'));
    }
    
    // Existing methods
    $this->get_facebook_ip_ranges();
    $this->get_openai_ip_ranges();
    $this->get_anthropic_ip_ranges();
    $this->get_telegram_ip_ranges();
    $this->get_perplexity_ip_ranges();
    $this->get_duckduckgo_ip_ranges();
    $this->get_linkedin_ip_ranges();
    $this->get_pinterest_ip_ranges();
    $this->get_twitter_ip_ranges();
    $this->get_discord_ip_ranges();
    $this->get_slack_ip_ranges();
    $this->get_baidu_ip_ranges();
    $this->get_yandex_ip_ranges();
    $this->get_ccbot_ip_ranges();
    $this->get_amazonbot_ip_ranges();
    $this->get_applebot_ip_ranges();
    $this->get_bytespider_ip_ranges();
    $this->get_youbot_ip_ranges();
    
    // SEO Crawlers
    $this->get_ahrefs_ip_ranges();
    $this->get_semrush_ip_ranges();
    $this->get_mj12_ip_ranges();
    $this->get_dotbot_ip_ranges();
    
    // AI Crawlers
    $this->get_tiktok_ip_ranges();
    
    // Regional Search Engines
    $this->get_seznam_ip_ranges();
    $this->get_petalbot_ip_ranges();
    
    if ($source === 'manual') {
        $this->log_action('SYSTEM', __('Manual update of all bot IP ranges completed.', 'bot-killer'));
    } else {
        $this->log_action('SYSTEM', __('Weekly update of all bot IP ranges completed.', 'bot-killer'));
    }
}

private function unblock_ip($ip) {
    // Remove from blocked IP file
    if (file_exists($this->block_file)) {
        $blocked = file($this->block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($blocked !== false) {
            $blocked = array_diff($blocked, array($ip));
            file_put_contents($this->block_file, implode("\n", $blocked) . "\n", LOCK_EX);
        }
    }
    
    // Remove from metadata
    $this->remove_block_meta($ip);
    
    // Clear cache
    wp_cache_delete('bot_killer_blocklist');
}


public function update_tor_exit_nodes($source = 'cron') {
    $url = 'https://check.torproject.org/exit-addresses';
    $response = wp_remote_get($url, [
        'timeout' => 15,
        'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        $this->log_action('SYSTEM', 'Failed to update Tor exit nodes list - ' . ($response->get_error_message() ?? 'Unknown error'));
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $lines = explode("\n", $body);
    $tor_ips = [];

    foreach ($lines as $line) {
        if (strpos($line, 'ExitAddress') === 0) {
            $parts = explode(' ', $line);
            if (isset($parts[1]) && filter_var($parts[1], FILTER_VALIDATE_IP)) {
                $tor_ips[] = $parts[1];
            }
        }
    }

    if (!empty($tor_ips)) {
        set_transient('bot_killer_tor_nodes', $tor_ips, 12 * HOUR_IN_SECONDS); // 12 hours
        
        // Log based on source
        if ($source === 'manual') {
            $this->log_action('SYSTEM', 'Tor exit nodes list manually updated. Total: ' . count($tor_ips) . ' IPs');
        } else {
            $this->log_action('SYSTEM', 'Tor exit nodes list updated via cron. Total: ' . count($tor_ips) . ' IPs');
        }
        
        return true;
    }

    $this->log_action('SYSTEM', 'Tor exit nodes list update failed - no IPs found');
    return false;
}

    private function is_tor_exit_node($ip) {
        $tor_ips = get_transient('bot_killer_tor_nodes');
        if (false === $tor_ips) {
            //$this->update_tor_exit_nodes();
            //$tor_ips = get_transient('bot_killer_tor_nodes');
            return false;
        }

        return is_array($tor_ips) && in_array($ip, $tor_ips);
    }

    private function check_rate_limit($ip, $endpoint) {
        $key = 'bot_killer_rate_' . md5($ip . '_' . $endpoint);
        $data = get_transient($key);
        $current_time = time();
        $window = 60;
        $limit = 10;
        
        if ($data === false) {
            $data = ['count' => 1, 'time' => $current_time];
            set_transient($key, $data, $window);
            return true;
        }
        
        if ($current_time - $data['time'] > $window) {
            $data = ['count' => 1, 'time' => $current_time];
            set_transient($key, $data, $window);
            return true;
        }
        
        if ($data['count'] >= $limit) return false;
        
        $data['count']++;
        set_transient($key, $data, $window);
        return true;
    }

    private function get_geo_location($ip) {
        if (get_option('bot_killer_disable_geoip', 0)) return null;
        
        $cache_key = 'bot_killer_geo_' . md5($ip);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['country_code' => 'private', 'city' => 'private', 'service' => 'local'];
        }
        
        $location = null;
        $geoip_service = get_option('bot_killer_geoip_service', 'freegeoip');
        $geoip_fallback = get_option('bot_killer_geoip_fallback', 1);
        
        if ($geoip_service === 'freegeoip') {
            $location = $this->geoip_lookup_freegeoip($ip);
        } elseif ($geoip_service === 'ip-api') {
            $location = $this->geoip_lookup_ip_api($ip);
        } elseif ($geoip_service === 'ipapi') {
            $location = $this->geoip_lookup_ipapi_co($ip);
        } elseif ($geoip_service === 'ipinfo') {
            $location = $this->geoip_lookup_ipinfo_io($ip);
        }
        
        if ($location === null && $geoip_fallback) {
            if ($location === null) $location = $this->geoip_lookup_ip_api($ip);
            if ($location === null) $location = $this->geoip_lookup_ipapi_co($ip);
            if ($location === null) $location = $this->geoip_lookup_ipinfo_io($ip);
        }
        
        if ($location !== null) {
            $cache_hours = get_option('bot_killer_geoip_cache_hours', 24);
            set_transient($cache_key, $location, $cache_hours * HOUR_IN_SECONDS);
        } else {
            set_transient($cache_key, null, HOUR_IN_SECONDS);
        }
        
        return $location;
    }

    private function geoip_lookup_freegeoip($ip) {
        $response = wp_remote_get('https://freegeoip.app/json/' . $ip, [
            'timeout' => 5,
            'sslverify' => true,
            'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
        ]);

        if (is_wp_error($response)) {
            return null;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code !== 200) {
            return null;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['country_code'])) {
            return [
                'country' => $data['country_name'] ?? 'unknown',
                'country_code' => $data['country_code'] ?? 'unknown',
                'region' => $data['region_name'] ?? 'unknown',
                'city' => $data['city'] ?? 'unknown',
                'service' => 'freegeoip.app'
            ];
        }
        
        return null;
    }

    private function geoip_lookup_ip_api($ip) {
        $response = wp_remote_get('https://ip-api.com/json/' . $ip . '?fields=status,country,countryCode,region,city,isp,org', 
            array('timeout' => 5, 'sslverify' => true, 'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($data['status']) && $data['status'] === 'success') {
                return ['country' => $data['country'] ?? 'unknown', 
                        'country_code' => $data['countryCode'] ?? 'unknown', 
                        'region' => $data['region'] ?? 'unknown', 
                        'city' => $data['city'] ?? 'unknown', 
                        'isp' => $data['isp'] ?? 'unknown', 
                        'org' => $data['org'] ?? 'unknown', 
                        'service' => 'ip-api.com'];
            }
        }
        return null;
    }

    private function geoip_lookup_ipapi_co($ip) {
        $response = wp_remote_get('https://ipapi.co/' . $ip . '/json/', 
            array('timeout' => 5, 'sslverify' => true, 'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!isset($data['error'])) {
                return ['country' => $data['country_name'] ?? 'unknown', 
                        'country_code' => $data['country_code'] ?? 'unknown', 
                        'region' => $data['region'] ?? 'unknown', 
                        'city' => $data['city'] ?? 'unknown', 
                        'isp' => $data['org'] ?? 'unknown', 
                        'org' => $data['org'] ?? 'unknown', 
                        'service' => 'ipapi.co'];
            }
        }
        return null;
    }

    private function geoip_lookup_ipinfo_io($ip) {
        $response = wp_remote_get('https://ipinfo.io/' . $ip . '/json', 
            array('timeout' => 5, 'sslverify' => true, 'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($data['country'])) {
                return ['country' => $data['country'] ?? 'unknown', 
                        'country_code' => $data['country'] ?? 'unknown', 
                        'region' => $data['region'] ?? 'unknown', 
                        'city' => $data['city'] ?? 'unknown', 
                        'isp' => $data['org'] ?? 'unknown', 
                        'org' => $data['org'] ?? 'unknown', 
                        'service' => 'ipinfo.io'];
            }
        }
        return null;
    }
    
    private function get_asn_for_ip($ip) {
        $cache_key = 'bot_killer_asn_' . md5($ip);
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $response = wp_remote_get('http://ip-api.com/json/' . $ip . '?fields=status,as,asname', [
            'timeout' => 3,
            'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
        ]);
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (isset($data['status']) && $data['status'] === 'success' && isset($data['as'])) {
                $as_parts = explode(' ', $data['as']);
                $as_number = str_replace('AS', '', $as_parts[0]);
                
                $result = [
                    'asn' => $as_number,
                    'as_name' => $data['asname'] ?? implode(' ', array_slice($as_parts, 1))
                ];
                
                set_transient($cache_key, $result, DAY_IN_SECONDS);
                return $result;
            }
        }
        
        return false;
    }

    private function get_ip() {
        $ip = $_SERVER['REMOTE_ADDR'];
        
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && !empty($this->cloudflare_ips)) {
            $proxy_ip = $_SERVER['REMOTE_ADDR'];
            $is_cloudflare = false;
            
            foreach ($this->cloudflare_ips['v4'] as $range) {
                if ($this->ip_in_range($proxy_ip, $range)) { 
                    $is_cloudflare = true; 
                    break; 
                }
            }
            
            if (!$is_cloudflare) {
                foreach ($this->cloudflare_ips['v6'] as $range) {
                    if ($this->ip_in_range($proxy_ip, $range)) { 
                        $is_cloudflare = true; 
                        break; 
                    }
                }
            }
            
            if ($is_cloudflare) {
                $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
                set_transient('bot_killer_cf_' . md5($ip), true, 3600);
                return $ip;
            }
        }
        
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $forwarded_ip = trim($ips[0]);
            if (filter_var($forwarded_ip, FILTER_VALIDATE_IP)) {
                return $forwarded_ip;
            }
        }
        
        if (isset($_SERVER['HTTP_TRUE_CLIENT_IP']) && filter_var($_SERVER['HTTP_TRUE_CLIENT_IP'], FILTER_VALIDATE_IP)) {
            return $_SERVER['HTTP_TRUE_CLIENT_IP'];
        }
        
        return $ip;
    }

    private function ip_in_range($ip, $range) {
        $range = trim($range);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $this->ipv6_in_range($ip, $range);
        }
        
        if (strpos($range, '/') !== false) {
            list($subnet, $mask) = explode('/', $range);
            $mask = intval(trim($mask));
            $subnet = trim($subnet);
            
            if ($mask < 0 || $mask > 32) return false;
            
            $ip_long = ip2long($ip);
            $subnet_long = ip2long($subnet);
            
            if ($ip_long === false || $subnet_long === false) return false;
            
            if ($mask == 0) {
                return true;
            }
            
            $mask_long = -1 << (32 - $mask);
            return (($ip_long & $mask_long) == ($subnet_long & $mask_long));
            
        } elseif (strpos($range, '-') !== false) {
            list($start, $end) = explode('-', $range);
            $start = trim($start);
            $end = trim($end);
            
            $ip_long = ip2long($ip);
            $start_long = ip2long($start);
            $end_long = ip2long($end);
            
            if ($ip_long === false || $start_long === false || $end_long === false) return false;
            
            return ($ip_long >= $start_long && $ip_long <= $end_long);
        } else {
            return $ip === $range;
        }
    }

    private function ipv6_in_range($ip, $range) {
        if (strpos($range, '/') !== false) {
            list($subnet, $mask) = explode('/', $range);
            $mask = intval($mask);
            
            $subnet = @inet_pton($subnet);
            $ip = @inet_pton($ip);
            
            if ($subnet === false || $ip === false) return false;
            
            if ($mask <= 0) return true;
            if ($mask >= 128) return $ip === $subnet;
            
            $bytes = floor($mask / 8);
            $bits = $mask % 8;
            
            for ($i = 0; $i < $bytes; $i++) {
                if ($ip[$i] !== $subnet[$i]) return false;
            }
            
            if ($bits > 0) {
                $mask_byte = chr(0xFF << (8 - $bits));
                return (($ip[$bytes] & $mask_byte) === ($subnet[$bytes] & $mask_byte));
            }
            
            return true;
        }
        return $ip === $range;
    }

    private function get_cached_blocklist() {
        $cached = wp_cache_get('bot_killer_blocklist');
        if (false === $cached) {
            $cached = [
                'blocked' => file_exists($this->block_file) ? 
                    file($this->block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [],
                'custom_block' => file_exists($this->custom_block_file) ? 
                    file($this->custom_block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [],
                'whitelist' => file_exists($this->custom_white_file) ? 
                    file($this->custom_white_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []
            ];
            wp_cache_set('bot_killer_blocklist', $cached, '', 300);
        }
        return $cached;
    }

    private function is_ip_in_custom_whitelist($ip) {
        $blocklist = $this->get_cached_blocklist();
        $custom_whites = $blocklist['whitelist'];
        
        foreach ($custom_whites as $white) {
            $white = trim($white);
            if (strpos($white, '#') === 0) continue;
            if (empty($white)) continue;
            if ($this->ip_in_range($ip, $white)) return true;
        }
        return false;
    }

    private function is_ip_in_custom_blocklist($ip) {
        $blocklist = $this->get_cached_blocklist();
        $custom_blocks = $blocklist['custom_block'];
        
        foreach ($custom_blocks as $block) {
            $block = trim($block);
            if (strpos($block, '#') === 0) continue;
            if (empty($block)) continue;
            if ($this->ip_in_range($ip, $block)) return true;
        }
        return false;
    }

    private function get_block_meta() {
        if (!file_exists($this->block_meta_file)) return [];
        $content = file_get_contents($this->block_meta_file);
        if ($content === false) return [];
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    private function save_block_meta($meta) {
        file_put_contents($this->block_meta_file, wp_json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
        if (is_writable($this->block_meta_file)) {
            chmod($this->block_meta_file, 0644);
        }
    }

// Method add_block_meta() - STORE GEOIP
private function add_block_meta($ip, $reason) {
    $meta = $this->get_block_meta();
    $block_time = time();
    $unblock_hours = get_option('bot_killer_unblock_hours', 24);
    $unblock_time = $block_time + ($unblock_hours * 3600);
    
    // THIS NEEDS TO REMAIN - GeoIP is stored once when blocking
    $geo = $this->get_geo_location($ip);
    
    $meta[$ip] = [
        'blocked_at' => $block_time, 
        'blocked_at_readable' => $this->get_current_time(), 
        'unblock_at' => $unblock_time, 
        'unblock_at_readable' => date('Y-m-d H:i:s', $unblock_time), 
        'reason' => $reason, 
        'geo' => $geo  // ← GeoIP is stored in meta
    ];
    
    $this->save_block_meta($meta);
    return $meta;
}


    private function remove_block_meta($ip) {
        $meta = $this->get_block_meta();
        if (isset($meta[$ip])) {
            unset($meta[$ip]);
            $this->save_block_meta($meta);
        }
    }

    private function is_prefetch_request() {
        $prefetch_headers = array(
            'HTTP_X_PURPOSE' => array('preview', 'prefetch'), 
            'HTTP_X_MOZ' => 'prefetch', 
            'HTTP_X_FB_HTTP_ENGINE' => 'Liger', 
            'HTTP_USER_AGENT' => array('prefetch', 'crawler', 'bot')
        );
        
        foreach ($prefetch_headers as $header => $values) {
            if (isset($_SERVER[$header])) {
                if (is_array($values)) {
                    foreach ($values as $value) {
                        if (stripos($_SERVER[$header], $value) !== false) return true;
                    }
                } else {
                    if (stripos($_SERVER[$header], $values) !== false) return true;
                }
            }
        }
        return false;
    }

    private function set_timezone() {
        $offset = $this->timezone_offset;
        if (preg_match('/^([+-])(\d{2}):(\d{2})$/', $offset, $matches)) {
            $sign = $matches[1] === '+' ? 1 : -1;
            $hours = intval($matches[2]);
            $minutes = intval($matches[3]);
            $total_seconds = $sign * ($hours * 3600 + $minutes * 60);
            $timezone_name = timezone_name_from_abbr('', $total_seconds, 0);
            if ($timezone_name) {
                $this->timezone = new DateTimeZone($timezone_name);
                return;
            }
            $this->timezone = new DateTimeZone($offset);
            return;
        }
        $this->timezone = new DateTimeZone('Europe/Helsinki');
    }

    private function get_current_time() {
        $now = new DateTime('now', $this->timezone);
        return $now->format('Y-m-d H:i:s');
    }

    private function rotate_log_if_needed() {
        if (!file_exists($this->log_file)) return;
        
        $size = filesize($this->log_file);
        if ($size > $this->max_log_size) {
            $backup = $this->log_file . '.' . date('Y-m-d-H-i-s');
            if (rename($this->log_file, $backup)) {
                if (is_writable($backup)) {
                    chmod($backup, 0644);
                }
                $current_time = $this->get_current_time();
                $header = "=== " . __('LOG ROTATED', 'bot-killer') . " at {$current_time} (" . __('previous log', 'bot-killer') . ": " . basename($backup) . ") ===\n================================================\n";
                file_put_contents($this->log_file, $header, LOCK_EX);
                if (is_writable($this->log_file)) {
                    chmod($this->log_file, 0644);
                }
                $this->cleanup_old_logs();
            }
        }
    }

    private function cleanup_old_logs() {
        $pattern = $this->log_file . '.*';
        $logs = glob($pattern);
        if (count($logs) > 5) {
            usort($logs, function($a, $b) { return filemtime($a) - filemtime($b); });
            $to_delete = array_slice($logs, 0, count($logs) - 5);
            foreach ($to_delete as $log) {
                if (file_exists($log)) {
                    unlink($log);
                }
            }
        }
    }

    public function create_files() {
        $files = [
            $this->log_file => "=== " . __('BOT KILLER LOG STARTED', 'bot-killer') . " at {$this->get_current_time()} ===\n================================================\n",
            $this->custom_block_file => "# " . __('Add IP ranges to block (one per line)', 'bot-killer') . "\n# " . __('Examples', 'bot-killer') . ":\n# 192.168.1.1\n# 10.0.0.0/24\n# 172.16.0.0-172.31.255.255\n# 2001:db8::/32\n",
            $this->custom_white_file => "# " . __('Add IP ranges to whitelist (one per line)', 'bot-killer') . "\n# " . __('Examples', 'bot-killer') . ":\n# 192.168.1.1\n# 10.0.0.0/24\n# 172.16.0.0-172.31.255.255\n# 2001:db8::/32\n#\n# " . __('Note: Whitelist bypasses all blocking rules', 'bot-killer') . "\n",
            $this->block_meta_file => wp_json_encode([]),
            $this->block_file => ""
        ];
        
        foreach ($files as $file => $content) {
            if (!file_exists($file)) {
                file_put_contents($file, $content, LOCK_EX);
                if (is_writable($file)) {
                    chmod($file, 0644);
                }
            }
        }
    }

private function is_ip_blocked($ip) {
    // ========== CHECK IF IP IS CURRENTLY BEING BLOCKED ==========
    $blocking_key = 'bot_killer_blocking_' . md5($ip);
    if (get_transient($blocking_key)) {
        return true; // Consider blocked while blocking is in progress
    }
    // =============================================================
    
    if ($this->is_ip_in_custom_whitelist($ip)) return false;
    
    $blocklist = $this->get_cached_blocklist();
    if (in_array($ip, $blocklist['blocked'])) {
        $meta = $this->get_block_meta();
        if (isset($meta[$ip]) && time() >= $meta[$ip]['unblock_at']) {
            if (file_exists($this->block_file)) {
                $blocked = file($this->block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (in_array($ip, $blocked)) {
                    $this->unblock_ip($ip);
                    if (!is_admin() && !defined('DOING_CRON')) {
                        $this->log_action($ip, "auto-unblocked during access check (expired)");
                    }
                }
            }
            return false;
        }
        return true;
    }
    return false;
}

    private function get_product_info($product_id) {
        if (!function_exists('wc_get_product')) return "ID: {$product_id}";
        $product = wc_get_product($product_id);
        if (!$product) return "ID: {$product_id} (" . __('invalid', 'bot-killer') . ")";
        $price = $product->get_price();
        $price_html = wc_price($price);
        $price_plain = wp_strip_all_tags($price_html);
        return "ID: {$product_id}, " . __('Price', 'bot-killer') . ": {$price_plain}";
    }

private function block_ip($ip, $reason) {
    // ========== CHECK IF IP IS CURRENTLY BEING BLOCKED ==========
    $blocking_key = 'bot_killer_blocking_' . md5($ip);
    
    // Atomic check-and-set
    if (!wp_cache_add($blocking_key, true, '', 3)) {
        return; 
    }
    
    if (get_transient($blocking_key)) {
        return; // IP already in blocking process, skip
    }
    set_transient($blocking_key, true, 3); // 3 seconds
    // =============================================================
    
    // Check whitelist
    if ($this->is_ip_in_custom_whitelist($ip)) {
        $this->log_action($ip, __("whitelisted ip - not blocked", 'bot-killer'));
        return;
    }

    $blocklist = $this->get_cached_blocklist();
    if (in_array($ip, $blocklist['blocked'])) {
        return;
    }

    $blocked = file_exists($this->block_file) ? 
        file($this->block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        
    if (!in_array($ip, $blocked)) {
        file_put_contents($this->block_file, $ip . "\n", FILE_APPEND | LOCK_EX);
        if (is_writable($this->block_file)) {
            chmod($this->block_file, 0644);
        }
    }

    $this->add_block_meta($ip, $reason);
    wp_cache_delete('bot_killer_blocklist');
    
    $unblock_hours = get_option('bot_killer_unblock_hours', 24);

    $this->log_action($ip, __("ip blocked", 'bot-killer') . " - {$reason} (" . sprintf(__('auto-unblock in %s hours', 'bot-killer'), $unblock_hours) . ")");
}

private function log_action($ip, $message) {
    $this->rotate_log_if_needed();
    $current_time = $this->get_current_time();
    
    if ($ip === 'SYSTEM') {
        $log_entry = "[{$current_time}] {$message}\n------------------------------------------------\n";
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
        return;
    }
    
    $geo = null;
    $location = '';
    
    // If IP is in blocklist - use saved data, don't make new request
    if ($this->is_ip_blocked($ip) || $this->is_ip_in_custom_blocklist($ip)) {
        $meta = $this->get_block_meta();
        if (isset($meta[$ip]) && isset($meta[$ip]['geo'])) {
            $geo = $meta[$ip]['geo'];
        } else {
            $location = $this->is_ip_in_custom_blocklist($ip) ? ' [custom blocklist]' : ' [auto-blocked]';
        }
    } else {
        // Only for NON-blocked IPs make new GeoIP request
        $geo = $this->get_geo_location($ip);
    }
    
    // Cloudflare detection
    $cloudflare_suffix = '';
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $proxy_ip = $_SERVER['REMOTE_ADDR'];
        $is_cloudflare = false;
        
        if (!empty($this->cloudflare_ips)) {
            foreach ($this->cloudflare_ips['v4'] as $range) {
                if ($this->ip_in_range($proxy_ip, $range)) { 
                    $is_cloudflare = true; 
                    break; 
                }
            }
            if (!$is_cloudflare) {
                foreach ($this->cloudflare_ips['v6'] as $range) {
                    if ($this->ip_in_range($proxy_ip, $range)) { 
                        $is_cloudflare = true; 
                        break; 
                    }
                }
            }
        }
        
        if ($is_cloudflare) {
            $cloudflare_suffix = ' - Cloudflare';
        }
    }
    
    if ($geo && is_array($geo) && isset($geo['country_code'])) {
        $city = !empty($geo['city']) ? $geo['city'] : __('unknown', 'bot-killer');
        $location = ' [' . $geo['country_code'] . ' - ' . $city . $cloudflare_suffix . ']';
    }
    
    $log_entry = "[{$current_time}] ip: {$ip}{$location} | {$message}\n------------------------------------------------\n";
    file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
}

    public function check_if_blocked() {
        if (is_admin() || is_user_logged_in()) return;
        
        $ip = $this->get_ip();
        
        if ($this->is_ip_blocked($ip)) {
            $this->log_action($ip, __("access attempt blocked", 'bot-killer'));
            if (defined('DOING_AJAX') && DOING_AJAX) {
                wp_send_json_error(__('Access Denied - IP Blocked', 'bot-killer'));
                wp_die();
            }
            add_action('wp', function() {
                wp_die(__('Access Denied - Your IP has been blocked due to suspicious activity.', 'bot-killer'), __('Blocked', 'bot-killer'), array('response' => 403));
            });
        }
    }

    public function add_no_js_check() {
        if (is_admin() || is_user_logged_in()) return;
        
        $ip = $this->get_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $cart_bot = $this->get_cart_interacting_bot($ip, $user_agent);
        if ($cart_bot) {
            return;
        }
        
        $block_browser = get_option('bot_killer_block_browser_integrity', 1);
        
        if (!$block_browser) return;
        
        if ($this->is_ip_in_custom_whitelist($ip)) return;
        
        echo '<noscript><div style="padding: 20px; text-align: center; background: #ffebee; border: 2px solid #f44336; margin: 20px; border-radius: 8px;"><p style="font-size: 16px; margin-bottom: 15px;">⚠️ ' . __('JavaScript, cookies, and referer headers are required for this site.', 'bot-killer') . '</p><a href="?bot_killer_no_js=1" style="background: #f44336; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block;">' . __('Continue (not recommended)', 'bot-killer') . '</a></div></noscript>';
    }
    
private function check_browser_integrity($ip) {
    // Skip Googlebot (has PageRenderer)
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (strpos($user_agent, 'Googlebot') !== false || 
        strpos($user_agent, 'Google-PageRenderer') !== false) {
        return true;
    }
    
    $missing_features = [];
    
    $js_detected = get_transient('bot_killer_js_detected_' . md5($ip));
    if (!$js_detected && !isset($_GET['bot_killer_no_js'])) {
        $missing_features[] = 'JavaScript';
    }
    
    if (empty($_COOKIE)) {
        $missing_features[] = 'Cookies';
    }
    
    if (!isset($_SERVER['HTTP_REFERER']) || empty($_SERVER['HTTP_REFERER'])) {
        $missing_features[] = 'Referer';
    }
    
    if (!empty($missing_features)) {
        $missing_str = implode(', ', $missing_features);
        $this->log_action($ip, "Browser integrity check failed - missing: {$missing_str}");
        
        $block_on_failure = get_option('bot_killer_block_browser_integrity', 1);
        
        if ($block_on_failure) {
            if (count($missing_features) === 1 && $missing_features[0] === 'Referer') {
                $this->log_action($ip, "Only referer missing - allowing");
                return true;
            }
            return false;
        }
    }
    
    return true;
}

private function is_headless_browser($user_agent) {
    // Don't block Google PageRenderer
    if (strpos($user_agent, 'Google-PageRenderer') !== false) {
        return false;
    }
    
    $headless_signatures = [
        'HeadlessChrome',
        'PhantomJS',
        'Puppeteer',
        'Playwright',
        'Selenium',
        'Headless',
        'selenium',
        'webdriver',
        'Cypress',
        'Nightmare',
        'Nightwatch'
    ];
    
    foreach ($headless_signatures as $signature) {
        if (stripos($user_agent, $signature) !== false) {
            return true;
        }
    }
    
    return false;
}

    public function add_js_detection() {
        if (is_admin() || is_user_logged_in()) return;
        
        if (isset($_SESSION['bot_killer_js_checked'])) return;
        $_SESSION['bot_killer_js_checked'] = true;
        
        $ip = $this->get_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $cart_bot = $this->get_cart_interacting_bot($ip, $user_agent);
        if ($cart_bot) {
            return;
        }
        
        set_transient('bot_killer_js_detected_' . md5($ip), true, 60 * MINUTE_IN_SECONDS);
        
        $block_browser = get_option('bot_killer_block_browser_integrity', 1);
        
        if (!$block_browser) return;
        
        if ($this->is_ip_in_custom_whitelist($ip)) return;
        
        ?>
        <script type="text/javascript">
        (function() {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) console.log('Bot Killer: Browser integrity confirmed');
            };
            xhr.send('action=bot_killer_js_detected&nonce=<?php echo wp_create_nonce('bot_killer_js_detection'); ?>');
        })();
        </script>
        <?php
    }

    public function handle_js_detection() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'bot_killer_js_detection')) {
            wp_send_json_error(__('Invalid security token', 'bot-killer'));
            return;
        }
        
        $ip = $this->get_ip();
        
        if (!$this->check_rate_limit($ip, 'js_detection')) {
            wp_send_json_error(__('Rate limit exceeded', 'bot-killer'));
            return;
        }
        
        set_transient('bot_killer_js_detected_' . md5($ip), true, 30 * MINUTE_IN_SECONDS);
        
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $cart_bot = $this->get_cart_interacting_bot($ip, $user_agent);
        
        if ($cart_bot || $this->is_ip_in_custom_whitelist($ip)) {
            wp_send_json_success('skipped');
            return;
        }
        
        wp_send_json_success('js_detected');
    }

    private function check_different_products_rule($ip, $product_id) {
        return true; // Rule removed
    }

    private function is_reserved_ip($ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        
        $reserved_ranges = [
            '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
            '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
            '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
            '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32'
        ];
        
        foreach ($reserved_ranges as $range) {
            if ($this->ip_in_range($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }

    private function check_custom_rules($ip, $product_id, $count, $time_span, $time_str, $product_info) {
        $custom_rules_enabled = get_option('bot_killer_custom_rules_enabled', 0);
        if (!$custom_rules_enabled) {
            return true;
        }
        
        $custom_rules = get_option('bot_killer_custom_rules', '');
        if (empty($custom_rules)) {
            return true;
        }
        
        $rules = explode("\n", $custom_rules);
        
        $unique_products_key = 'bot_killer_unique_products_' . md5($ip);
        $unique_products_data = get_transient($unique_products_key);
        $unique_count = 0;
        $current_time = time();
        
        if ($unique_products_data && isset($unique_products_data['products'])) {
            $unique_products_data['products'] = array_filter(
                $unique_products_data['products'],
                function($time) use ($current_time) {
                    return ($current_time - $time) < 3600;
                }
            );
            $unique_count = count($unique_products_data['products']);
            set_transient($unique_products_key, $unique_products_data, 3600);
        }
        
        foreach ($rules as $rule) {
            $rule = trim($rule);
            if (empty($rule) || strpos($rule, '#') === 0) continue;
            
            $parts = explode(',', $rule);
            $parts = array_map('trim', $parts);
            
            if (count($parts) >= 2) {
                $custom_attempts = intval($parts[0]);
                $custom_seconds = intval($parts[1]);
                $custom_type = isset($parts[2]) ? intval($parts[2]) : 2;
                
                $type_matched = false;
                $actual_count = 0;
                
                switch ($custom_type) {
                    case 0:
                        if ($count >= $custom_attempts) {
                            $type_matched = true;
                            $actual_count = $count;
                        }
                        break;
                        
                    case 1:
                        if ($unique_count >= $custom_attempts) {
                            $type_matched = true;
                            $actual_count = $unique_count;
                        }
                        break;
                        
                    case 2:
                        if ($count >= $custom_attempts) {
                            $type_matched = true;
                            $actual_count = $count;
                        }
                        break;
                }
                
                if ($type_matched && $time_span < $custom_seconds) {
                    $type_text = $this->get_custom_rule_type_text($custom_type);
                    $this->block_ip($ip, sprintf(
                        __("custom rule (%s): %d %s in %ds (actual: %d in %s)", 'bot-killer'),
                        $type_text,
                        $custom_attempts,
                        ($custom_type == 0 ? 'same products' : 'different products'),
                        $custom_seconds,
                        $actual_count,
                        $time_str
                    ) . " - " . $product_info);
                    
                    wc_add_notice(__('Access Denied - Too many attempts'), 'error');
                    return false;
                }
            }
        }
        
        return true;
    }

    private function get_custom_rule_type_text($type) {
        switch ($type) {
            case 0:
                return 'same product';
            case 1:
                return 'different products';
            default:
                return 'any products';
        }
    }

public function track_and_block($passed, $product_id, $quantity) {
    // Allow logged-in users
    if (is_user_logged_in()) {
        $product = wc_get_product($product_id);
        if (!$product) {
            wc_add_notice(__('Invalid product', 'bot-killer'), 'error');
            return false;
        }
        
        $ip = $this->get_ip();
        $product_price = $product->get_price();
        
        $user = wp_get_current_user();
        $is_admin = in_array('administrator', $user->roles) || in_array('shop_manager', $user->roles);
        $admin_suffix = $is_admin ? ' [ADMIN]' : '';
        
        $this->log_action($ip, sprintf(
            "ADD TO CART - Product ID: %d, Qty: %d, Price: %s%s",
            $product_id,
            $quantity,
            $product_price,
            $admin_suffix
        ));
        
        return $passed;
    }
    
    // For non-logged in users
    if (!function_exists('wc_get_product') || !function_exists('wc_add_notice')) {
        return $passed;
    }
    
    if ($this->is_prefetch_request()) {
        return $passed;
    }
    
    $product = wc_get_product($product_id);
    if (!$product) {
        wc_add_notice(__('Invalid product', 'bot-killer'), 'error');
        return false;
    }
    
    $ip = $this->get_ip();
    $is_cloudflare = get_transient('bot_killer_cf_' . md5($ip));
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Track unique products for custom rules
    $unique_products_key = 'bot_killer_unique_products_' . md5($ip);
    $unique_products_data = get_transient($unique_products_key);
    $current_time = time();

    if ($unique_products_data === false) {
        $unique_products_data = [
            'products' => [$product_id => $current_time],
            'first_seen' => $current_time
        ];
    } else {
        if (isset($unique_products_data['products']) && is_array($unique_products_data['products'])) {
            $unique_products_data['products'] = array_filter(
                $unique_products_data['products'],
                function($time) use ($current_time) {
                    return ($current_time - $time) < 3600;
                }
            );
        } else {
            $unique_products_data['products'] = [];
        }
        
        $unique_products_data['products'][$product_id] = $current_time;
    }

    set_transient($unique_products_key, $unique_products_data, 3600);
    
    // =============================================
    // PRIORITY 1: WHITELIST - Highest priority
    // =============================================
    if ($this->is_ip_in_custom_whitelist($ip)) {
        $this->log_action($ip, __("whitelisted ip - activity allowed"));
        return $passed;
    }

    // PRIORITY 1.5: CHECK IF ALREADY BLOCKED
    if ($this->is_ip_blocked($ip)) {
        wc_add_notice(__('Access Denied - IP Blocked'), 'error');
        return false;
    }
    
    // =============================================
    // PRIORITY 2: VERIFIED BOTS - Bypass ALL other checks
    // =============================================
    $cart_bot = false;
    $verification_method = '';
    
    // Method 1: Standard bot detection from cart_bots array
    $cart_bot = $this->get_cart_interacting_bot($ip, $user_agent);
    if ($cart_bot) {
        $verification_method = 'standard';
    }
    
    // Method 2: Direct DNS verification for common bots if not detected
    if (!$cart_bot) {
        $hostname = $this->reverse_dns_lookup($ip, 2);
        
        if ($hostname) {
            // Googlebot check
            if (strpos($hostname, '.googlebot.com') !== false || strpos($hostname, '.google.com') !== false) {
                $forward_ips = $this->forward_dns_lookup($hostname, 2);
                if ($forward_ips && is_array($forward_ips) && in_array($ip, $forward_ips)) {
                    $cart_bot = 'google';
                    $verification_method = 'dns_forward';
                }
            }
            // Bingbot check
            elseif (strpos($hostname, '.search.msn.com') !== false) {
                $forward_ips = $this->forward_dns_lookup($hostname, 2);
                if ($forward_ips && is_array($forward_ips) && in_array($ip, $forward_ips)) {
                    $cart_bot = 'bing';
                    $verification_method = 'dns_forward';
                }
            }
            // Yandex check
            elseif (strpos($hostname, '.yandex.ru') !== false || strpos($hostname, '.yandex.net') !== false) {
                $forward_ips = $this->forward_dns_lookup($hostname, 2);
                if ($forward_ips && is_array($forward_ips) && in_array($ip, $forward_ips)) {
                    $cart_bot = 'yandex';
                    $verification_method = 'dns_forward';
                }
            }
            // CCBot check
            elseif (strpos($hostname, '.crawl.commoncrawl.org') !== false) {
                $forward_ips = $this->forward_dns_lookup($hostname, 2);
                if ($forward_ips && is_array($forward_ips) && in_array($ip, $forward_ips)) {
                    $cart_bot = 'ccbot';
                    $verification_method = 'dns_forward';
                }
            }
            // Amazonbot check
            elseif (strpos($hostname, '.crawl.amazonbot.amazon') !== false) {
                $forward_ips = $this->forward_dns_lookup($hostname, 2);
                if ($forward_ips && is_array($forward_ips) && in_array($ip, $forward_ips)) {
                    $cart_bot = 'amazonbot';
                    $verification_method = 'dns_forward';
                }
            }
            // Applebot check
            elseif (strpos($hostname, '.applebot.apple.com') !== false) {
                $forward_ips = $this->forward_dns_lookup($hostname, 2);
                if ($forward_ips && is_array($forward_ips) && in_array($ip, $forward_ips)) {
                    $cart_bot = 'applebot';
                    $verification_method = 'dns_forward';
                }
            }
        }
    }
    
    // Method 3: IP range verification for bots without DNS
    if (!$cart_bot) {
        // Google IP ranges
        foreach ($this->google_ips as $range) {
            if ($this->ip_in_range($ip, $range)) {
                $cart_bot = 'google';
                $verification_method = 'ip_range';
                break;
            }
        }
        
        if (!$cart_bot) {
            // Bing IP ranges
            foreach ($this->bing_ips as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'bing';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Facebook IP ranges
            $facebook_ranges = $this->get_facebook_ip_ranges();
            foreach ($facebook_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'facebook';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Ahrefs IP ranges
            $ahrefs_ranges = $this->get_ahrefs_ip_ranges();
            foreach ($ahrefs_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'ahrefs';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Semrush IP ranges
            $semrush_ranges = $this->get_semrush_ip_ranges();
            foreach ($semrush_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'semrush';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // MJ12bot IP ranges
            $mj12_ranges = $this->get_mj12_ip_ranges();
            foreach ($mj12_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'mj12bot';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // DotBot IP ranges
            $dotbot_ranges = $this->get_dotbot_ip_ranges();
            foreach ($dotbot_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'dotbot';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // OpenAI IP ranges
            $openai_ranges = $this->get_openai_ip_ranges();
            foreach ($openai_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'openai';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Anthropic IP ranges
            $anthropic_ranges = $this->get_anthropic_ip_ranges();
            foreach ($anthropic_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'anthropic';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Telegram IP ranges
            $telegram_ranges = $this->get_telegram_ip_ranges();
            foreach ($telegram_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'telegram';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Perplexity IP ranges
            $perplexity_ranges = $this->get_perplexity_ip_ranges();
            foreach ($perplexity_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'perplexity';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Baidu IP ranges
            $baidu_ranges = $this->get_baidu_ip_ranges();
            foreach ($baidu_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'baidu';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Yandex IP ranges
            $yandex_ranges = $this->get_yandex_ip_ranges();
            foreach ($yandex_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'yandex';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // DuckDuckGo IP ranges
            $duckduckgo_ranges = $this->get_duckduckgo_ip_ranges();
            foreach ($duckduckgo_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'duckduckgo';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // LinkedIn IP ranges
            $linkedin_ranges = $this->get_linkedin_ip_ranges();
            foreach ($linkedin_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'linkedin';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Pinterest IP ranges
            $pinterest_ranges = $this->get_pinterest_ip_ranges();
            foreach ($pinterest_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'pinterest';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Twitter IP ranges
            $twitter_ranges = $this->get_twitter_ip_ranges();
            foreach ($twitter_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'twitter';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Discord IP ranges
            $discord_ranges = $this->get_discord_ip_ranges();
            foreach ($discord_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'discord';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Slack IP ranges
            $slack_ranges = $this->get_slack_ip_ranges();
            foreach ($slack_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'slack';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Cloudflare IP ranges
            if (!empty($this->cloudflare_ips)) {
                foreach ($this->cloudflare_ips['v4'] as $range) {
                    if ($this->ip_in_range($ip, $range)) {
                        $cart_bot = 'cloudflare';
                        $verification_method = 'ip_range';
                        break;
                    }
                }
                if (!$cart_bot) {
                    foreach ($this->cloudflare_ips['v6'] as $range) {
                        if ($this->ip_in_range($ip, $range)) {
                            $cart_bot = 'cloudflare';
                            $verification_method = 'ip_range';
                            break;
                        }
                    }
                }
            }
        }
        
        if (!$cart_bot) {
            // Bytespider IP ranges
            $bytespider_ranges = $this->get_bytespider_ip_ranges();
            foreach ($bytespider_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'bytespider';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // Seznam IP ranges
            $seznam_ranges = $this->get_seznam_ip_ranges();
            foreach ($seznam_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'seznam';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // PetalBot IP ranges
            $petalbot_ranges = $this->get_petalbot_ip_ranges();
            foreach ($petalbot_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'petalbot';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
        
        if (!$cart_bot) {
            // TikTok IP ranges
            $tiktok_ranges = $this->get_tiktok_ip_ranges();
            foreach ($tiktok_ranges as $range) {
                if ($this->ip_in_range($ip, $range)) {
                    $cart_bot = 'tiktok';
                    $verification_method = 'ip_range';
                    break;
                }
            }
        }
    }
    
    // Method 4: ASN verification
    if (!$cart_bot) {
        $asn_info = $this->get_asn_for_ip($ip);
        if ($asn_info) {
            $asn = $asn_info['asn'];
            
            // Bot ASN mappings
            $bot_asns = [
                '15169' => 'google',      // Google
                '32934' => 'facebook',    // Facebook
                '63293' => 'facebook',    // Facebook
                '209242' => 'ahrefs',     // Ahrefs
                '203726' => 'semrush',    // Semrush
                '204734' => 'mj12bot',    // Majestic
                '8068' => 'bing',         // Microsoft
                '8069' => 'bing',         // Microsoft
                '8070' => 'bing',         // Microsoft
                '8071' => 'bing',         // Microsoft
                '8072' => 'bing',         // Microsoft
                '8073' => 'bing',         // Microsoft
                '8074' => 'bing',         // Microsoft
                '8075' => 'bing',         // Microsoft
                '62041' => 'telegram',    // Telegram
                '59930' => 'telegram',    // Telegram
                '43037' => 'seznam',      // Seznam
                '136907' => 'petalbot',   // PetalBot
                '396982' => 'tiktok',     // TikTok
                '45102' => 'tiktok',      // TikTok
                '46475' => 'discord',     // Discord
                '36978' => 'discord',     // Discord
                '14413' => 'linkedin',    // LinkedIn
                '40027' => 'pinterest',   // Pinterest
                '13414' => 'twitter',     // Twitter
                '42729' => 'duckduckgo',  // DuckDuckGo
                '16509' => 'amazonbot',   // Amazon AWS (CCBot, Amazonbot)
                '714' => 'applebot',      // Apple
                '6185' => 'applebot',     // Apple
                '37963' => 'bytespider',  // ByteDance
                '45090' => 'bytespider',  // ByteDance
                '13335' => 'cloudflare',  // Cloudflare
                '209242' => 'cloudflare', // Cloudflare
                '13238' => 'yandex',      // Yandex
                '208722' => 'yandex',     // Yandex
                '55967' => 'baidu',       // Baidu
                '37965' => 'baidu',       // Baidu
            ];
            
            if (isset($bot_asns[$asn])) {
                $cart_bot = $bot_asns[$asn];
                $verification_method = 'asn_match';
            }
        }
    }
    
    // SINGLE LOG ENTRY using your existing log_bot_detection method
    if ($cart_bot) {
        $this->log_bot_detection($ip, $cart_bot, $user_agent, $verification_method);
        return $passed; // Verified bots bypass everything below
    }
    
    // =============================================
    // PRIORITY 3: BLOCKLISTS - For non-bot traffic
    // =============================================
    
    // 3a. CUSTOM BLOCKLIST
    if ($this->is_ip_in_custom_blocklist($ip)) {
        wc_add_notice(__('Access Denied - IP Blocked'), 'error');
        $this->log_action($ip, __("blocked by custom blocklist"));
        return false;
    }
    
    // 3b. AUTO-BLOCKED
    if ($this->is_ip_blocked($ip)) {
        wc_add_notice(__('Access Denied - IP Blocked'), 'error');
        //$this->log_action($ip, __("blocked attempt - already in auto-block list"));
        return false;
    }
    
    // 3c. TOR EXIT NODES
    if (get_option('bot_killer_block_tor', 1)) {
        if ($this->is_tor_exit_node($ip)) {
            $this->log_action($ip, "Tor exit node detected and blocked");
            wc_add_notice(__('Access Denied - Anonymizer detected'), 'error');
            return false;
        }
    }
    
    // 3d. ASN BLOCK
    $blocked_asns = get_option('bot_killer_blocked_asns', []);
    if (!empty($blocked_asns)) {
        $asn_info = $this->get_asn_for_ip($ip);
        if ($asn_info && isset($asn_info['asn']) && in_array($asn_info['asn'], $blocked_asns)) {
            $this->log_action($ip, "ASN {$asn_info['asn']} ({$asn_info['as_name']}) blocked");
            wc_add_notice(__('Access Denied - Network blocked'), 'error');
            return false;
        }
    }
    
// =============================================
// PRIORITY 4: BROWSER CHECKS - For non-bot traffic
// =============================================

// 4a. HEADLESS DETECTION (now first)
if (get_option('bot_killer_block_headless', 1)) {
    if ($this->is_headless_browser($user_agent)) {
        $this->log_action($ip, "Headless browser detected - " . $user_agent);
        wc_add_notice(__('Access Denied - Automated browser detected'), 'error');
        return false;
    }
}

// 4b. BROWSER INTEGRITY (now second)
if (!$this->check_browser_integrity($ip)) {
    $this->block_ip($ip, "No JS/cookies/referer");
    wc_add_notice(__('Your browser is not fully supported. Please enable JavaScript and cookies.', 'bot-killer'), 'error');
    return false;
}
    
// =============================================
// PRIORITY 5: GEO CHECKS - For non-bot traffic
// =============================================

// 5a. COUNTRY FILTER - only if IP is not in custom block list
$allowed_countries = get_option('bot_killer_allowed_countries', array());
$block_unknown = get_option('bot_killer_block_unknown_country', 0);

if (!empty($allowed_countries) && !$this->is_ip_in_custom_blocklist($ip)) {
    $geo = $this->get_geo_location($ip);
    $country_code = is_array($geo) ? ($geo['country_code'] ?? null) : null;
    
    if ($country_code && $country_code !== 'private') {
        if (!in_array($country_code, $allowed_countries)) {
            $this->log_action($ip, sprintf(__("blocked - country %s not allowed"), $country_code));
            wc_add_notice(__('Purchases are only available from selected countries'), 'error');
            return false;
        }
    } else {
        if ($block_unknown) {
            $this->log_action($ip, __("blocked - unknown country"));
            wc_add_notice(__('Unable to verify your location'), 'error');
            return false;
        }
    }
}
    
    // =============================================
    // PRIORITY 6: PRODUCT CHECKS - For non-bot traffic
    // =============================================
    
    // 6a. OUT-OF-STOCK CHECK
    $block_out_of_stock = get_option('bot_killer_block_out_of_stock', 1);
    if ($block_out_of_stock && !$product->is_in_stock()) {
        $this->block_ip($ip, __("Attempt to add out-of-stock product"));
        wc_add_notice(__('This product is out of stock'), 'error');
        return false;
    }
    
    // =============================================
    // PRIORITY 7: RATE LIMITING RULES - Custom Rules only
    // =============================================
    
    // 7a. CUSTOM RULES (Rule 4)
    $transient_key = 'bot_killer_' . md5($product_id . '_' . $ip);
    $session_data = get_transient($transient_key);
    
    $custom_rules_enabled = get_option('bot_killer_custom_rules_enabled', 0);
    $custom_rules = get_option('bot_killer_custom_rules', '');
    
    $max_expiration = 3600; // Default 1 hour max for custom rules
    
    if ($custom_rules_enabled && !empty($custom_rules)) {
        $rules = explode("\n", $custom_rules);
        foreach ($rules as $rule) {
            $rule = trim($rule);
            if (empty($rule) || strpos($rule, '#') === 0) continue;
            $parts = explode(',', $rule);
            if (count($parts) >= 2) {
                $custom_seconds = intval(trim($parts[1]));
                if ($custom_seconds > $max_expiration) {
                    $max_expiration = $custom_seconds;
                }
            }
        }
    }
    
    $expiration = $max_expiration + 60;
    
    if ($session_data === false) {
        $session_data = [
            'count' => 1,
            'first_time' => time(),
            'last_time' => time()
        ];
        set_transient($transient_key, $session_data, $expiration);
    } else {
        $count = $session_data['count'] + 1;
        $first_time = $session_data['first_time'];
        $last_time = time();
        $time_span = $last_time - $first_time;
        $minutes = floor($time_span / 60);
        $seconds = $time_span % 60;
        $time_str = $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";
        
        $session_data['count'] = $count;
        $session_data['last_time'] = $last_time;
        set_transient($transient_key, $session_data, $expiration);
        
        // Custom Rules only
        if (!$this->check_custom_rules($ip, $product_id, $count, $time_span, $time_str, $this->get_product_info($product_id))) {
            return false;
        }
    }
    
    // Log add to cart
    $product_price = $product->get_price();
    
    $is_admin = false;
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('administrator', $user->roles) || in_array('shop_manager', $user->roles)) {
            $is_admin = true;
        }
    }
    
    $admin_suffix = $is_admin ? ' [ADMIN]' : '';
    $cloudflare_suffix = $is_cloudflare ? ' [CF]' : '';
    
    $this->log_action($ip, sprintf(
        "ADD TO CART - Product ID: %d, Qty: %d, Price: %s%s%s",
        $product_id,
        $quantity,
        $product_price,
        $admin_suffix,
        $cloudflare_suffix
    ));
    
    return $passed;
}

public function track_order($order_id) {
    $logged = get_post_meta($order_id, '_bot_killer_logged', true);
    if ($logged) {
        return;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    $ip = $this->get_ip();
    
    // Detect if request came through Cloudflare
    $cloudflare_suffix = '';
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $proxy_ip = $_SERVER['REMOTE_ADDR'];
        $is_cloudflare = false;
        
        foreach ($this->cloudflare_ips['v4'] as $range) {
            if ($this->ip_in_range($proxy_ip, $range)) { 
                $is_cloudflare = true; 
                break; 
            }
        }
        if (!$is_cloudflare) {
            foreach ($this->cloudflare_ips['v6'] as $range) {
                if ($this->ip_in_range($proxy_ip, $range)) { 
                    $is_cloudflare = true; 
                    break; 
                }
            }
        }
        if ($is_cloudflare) {
            $cloudflare_suffix = ' - Cloudflare';
        }
    }
    
    $total = $order->get_total();
    $item_count = $order->get_item_count();
    
    $this->log_action($ip, sprintf(
        "PURCHASE - Order #%d: %d items, Total: %s%s",
        $order_id,
        $item_count,
        $total,
        $cloudflare_suffix
    ));
    
    update_post_meta($order_id, '_bot_killer_logged', true);
}

public function track_remove_from_cart($cart_item_key, $cart) {
    if (is_admin() || !function_exists('wc_get_product')) {
        return;
    }
    
    $cart_item = $cart->removed_cart_contents[$cart_item_key] ?? null;
    if (!$cart_item) {
        return;
    }
    
    $product_id = $cart_item['product_id'];
    $quantity = $cart_item['quantity'];
    $ip = $this->get_ip();
    
    $product = wc_get_product($product_id);
    if (!$product) {
        return;
    }
    
    $product_price = $product->get_price();
    
    $is_admin = false;
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('administrator', $user->roles) || in_array('shop_manager', $user->roles)) {
            $is_admin = true;
        }
    }
    
    $admin_suffix = $is_admin ? ' [ADMIN]' : '';
    
    $this->log_action($ip, sprintf(
        "REMOVE FROM CART - Product ID: %d, Qty: %d, Price: %s%s",
        $product_id,
        $quantity,
        $product_price,
        $admin_suffix
    ));
}

    public function cleanup_expired_blocks() {
        $meta = $this->get_block_meta();
        $changed = false;
        $current_time = time();
        $unblock_hours = get_option('bot_killer_unblock_hours', 24);
        
        if (!file_exists($this->block_file)) return;
        
        $blocked_ips = file($this->block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($blocked_ips === false) return;
        
        $new_blocked = [];
        $unblocked_count = 0;
        
        foreach ($blocked_ips as $ip) {
            $ip = trim($ip);
            if (empty($ip)) continue;
            
            if (isset($meta[$ip])) {
                if ($current_time >= $meta[$ip]['unblock_at']) {
                    unset($meta[$ip]);
                    $changed = true;
                    $unblocked_count++;
                    $this->log_action($ip, __("auto-unblocked after", 'bot-killer') . " {$unblock_hours} " . __('hours', 'bot-killer'));
                } else {
                    $new_blocked[] = $ip;
                }
            } else {
                $new_blocked[] = $ip;
            }
        }
        
        if (!empty($new_blocked)) {
            file_put_contents($this->block_file, implode("\n", $new_blocked) . "\n", LOCK_EX);
        } else {
            file_put_contents($this->block_file, "", LOCK_EX);
        }
        
        if (is_writable($this->block_file)) {
            chmod($this->block_file, 0644);
        }
        
        if ($changed) {
            $this->save_block_meta($meta);
            wp_cache_delete('bot_killer_blocklist');
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook('bot_killer_cleanup_expired_blocks');
        wp_clear_scheduled_hook('bot_killer_update_cloudflare_ips');
        wp_clear_scheduled_hook('bot_killer_update_bot_ips');
        wp_clear_scheduled_hook('bot_killer_update_tor_nodes');
    }

public function register_settings() {
    $settings = [
        'bot_killer_timezone' => ['sanitize_callback' => [$this, 'sanitize_timezone']],
        'bot_killer_custom_rules_enabled' => ['sanitize_callback' => 'intval'],
        'bot_killer_custom_rules' => ['sanitize_callback' => [$this, 'sanitize_custom_rules']],
        'bot_killer_unblock_hours' => ['sanitize_callback' => [$this, 'sanitize_unblock_hours']],
        'bot_killer_block_no_js' => ['sanitize_callback' => 'intval'],
        'bot_killer_max_log_size' => ['sanitize_callback' => [$this, 'sanitize_max_log_size']],
        'bot_killer_allowed_countries' => ['sanitize_callback' => [$this, 'sanitize_countries']],
        'bot_killer_block_unknown_country' => ['sanitize_callback' => 'intval'],
        'bot_killer_disable_geoip' => ['sanitize_callback' => 'intval'],
        'bot_killer_geoip_cache_hours' => ['sanitize_callback' => 'intval'],
        'bot_killer_geoip_fallback' => ['sanitize_callback' => 'intval'],
        'bot_killer_geoip_service' => ['sanitize_callback' => 'sanitize_text_field'],
        'bot_killer_block_out_of_stock' => ['sanitize_callback' => 'intval'],
        'bot_killer_block_browser_integrity' => ['sanitize_callback' => 'intval'],
        'bot_killer_block_headless' => ['sanitize_callback' => 'intval'],
        'bot_killer_block_tor' => ['sanitize_callback' => 'intval'],
        'bot_killer_blocked_asns' => ['sanitize_callback' => [$this, 'sanitize_asn_list']],
    ];
    
    foreach ($settings as $setting => $args) {
        register_setting('bot_killer_settings', $setting, $args);
    }
}
    
public function sanitize_custom_rules($value) {
    if (empty($value)) {
        // Return default rules if empty
        return "# 2 same products in 5 seconds\n2,5,0\n\n# 3 same products in 5 minutes\n3,300,0\n\n# 2 different products in 5 seconds\n2,5,1";
    }
    
    $lines = explode("\n", $value);
    $clean_lines = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        
        if (strpos($line, '#') === 0) {
            $clean_lines[] = $line;
            continue;
        }
        
        if (empty($line)) {
            $clean_lines[] = '';
            continue;
        }
        
        $parts = explode(',', $line);
        $parts = array_map('trim', $parts);
        
        if (count($parts) >= 2) {
            $attempts = intval($parts[0]);
            $seconds = intval($parts[1]);
            $type = isset($parts[2]) ? intval($parts[2]) : 2;
            
            if ($attempts >= 2 && $attempts <= 100 && 
                $seconds >= 5 && $seconds <= 86400 &&
                in_array($type, [0, 1, 2])) {
                
                $clean_lines[] = $attempts . ',' . $seconds . ',' . $type;
            }
        }
    }
    
    return implode("\n", $clean_lines);
}
    
    public function sanitize_asn_list($value) {
        if (is_string($value) && strpos($value, '[') === 0) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        
        if (is_array($value)) {
            $clean_asns = [];
            foreach ($value as $asn) {
                $clean_asn = preg_replace('/[^0-9]/', '', $asn);
                if (!empty($clean_asn) && is_numeric($clean_asn) && $clean_asn > 0 && $clean_asn < 1000000) {
                    $clean_asns[] = $clean_asn;
                }
            }
            return $clean_asns;
        }
        
        if (empty($value)) {
            return [];
        }
        
        $lines = explode("\n", $value);
        $clean_asns = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            if (strpos($line, '#') !== false) {
                $line = trim(substr($line, 0, strpos($line, '#')));
                if (empty($line)) continue;
            }
            
            $asn = preg_replace('/[^0-9]/', '', $line);
            
            if (!empty($asn) && is_numeric($asn) && $asn > 0 && $asn < 1000000) {
                $clean_asns[] = $asn;
            }
        }
        
        return $clean_asns;
    }

    public function sanitize_countries($countries) {
        if (!is_array($countries)) return array();
        return array_map('sanitize_text_field', $countries);
    }

    public function sanitize_timezone($value) {
        if (preg_match('/^[+-]\d{2}:\d{2}$/', $value)) return $value;
        return '+02:00';
    }

    public function sanitize_unblock_hours($value) {
        $value = intval($value);
        if ($value < 1) return 24;
        if ($value > 720) return 720;
        return $value;
    }

    public function sanitize_max_log_size($value) {
        $value = intval($value);
        if ($value < 1) $value = 10;
        if ($value > 1000) $value = 1000;
        return $value;
    }

    public function admin_notices() {
        settings_errors('bot_killer_settings');
        
        if (empty($this->google_ips) || empty($this->bing_ips)) {
            echo '<div class="notice notice-warning"><p>' . __('Bot Killer: Could not fetch search engine IP ranges. Using fallback lists which may be outdated.', 'bot-killer') . '</p></div>';
        }
        
        if (empty($this->cloudflare_ips['v4']) && empty($this->cloudflare_ips['v6'])) {
            echo '<div class="notice notice-warning"><p>' . __('Bot Killer: Cloudflare IPs not loaded. Proxy detection may not work correctly until cron updates them.', 'bot-killer') . '</p></div>';
        }
        
        if (!file_exists($this->upload_dir) || !is_writable($this->upload_dir)) {
            echo '<div class="notice notice-error"><p>' . __('Bot Killer: Upload directory is not writable. Please check permissions.', 'bot-killer') . '</p></div>';
        }
        
        echo '<div class="notice notice-info"><p>🔒 ' . __('Bot Killer: IP addresses are logged for security purposes. External API calls are made to ip-api.com (GeoIP) and Cloudflare (IP ranges).', 'bot-killer') . '</p></div>';
    }

    public function admin_menu() {
        add_menu_page(
            __('Bot Killer', 'bot-killer'), 
            __('Bot Killer', 'bot-killer'), 
            'manage_options', 
            'bot-killer', 
            array($this, 'admin_page'), 
            'dashicons-shield', 
            80
        );
        
        add_submenu_page(
            'bot-killer', 
            __('Settings', 'bot-killer'), 
            __('Settings', 'bot-killer'), 
            'manage_options', 
            'bot-killer-settings', 
            array($this, 'settings_page')
        );
    }

public function settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'bot-killer'));
    }
    
    // Handle Blocklist save
    if (isset($_POST['save_blocklist']) && check_admin_referer('bot_killer_ip_lists')) {
        if (isset($_POST['blocklist_ips'])) {
            $block_ips = wp_unslash($_POST['blocklist_ips']);
            $block_ips = str_replace(array("\r\n", "\r"), "\n", $block_ips);
            $block_ips = sanitize_textarea_field($block_ips);
            
            $lines = explode("\n", $block_ips);
            $valid_lines = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Keep comments
                if (strpos($line, '#') === 0) {
                    $valid_lines[] = $line;
                    continue;
                }
                
                // Clean and validate IP/range
                $clean_line = preg_replace('/[^a-zA-Z0-9:.\/#\-]/', '', $line);
                if (!empty($clean_line)) {
                    $valid_lines[] = $clean_line;
                }
            }
            
            // Save to file
            file_put_contents($this->custom_block_file, implode("\n", $valid_lines), LOCK_EX);
            if (is_writable($this->custom_block_file)) {
                chmod($this->custom_block_file, 0644);
            }
            
            wp_cache_delete('bot_killer_blocklist');
            
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(__('Blocklist saved successfully! %d entries stored.', 'bot-killer'), count($valid_lines)) . 
                 '</p></div>';
        }
    }
    
    // Handle Whitelist save
    if (isset($_POST['save_whitelist']) && check_admin_referer('bot_killer_ip_lists')) {
        if (isset($_POST['whitelist_ips'])) {
            $white_ips = wp_unslash($_POST['whitelist_ips']);
            $white_ips = str_replace(array("\r\n", "\r"), "\n", $white_ips);
            $white_ips = sanitize_textarea_field($white_ips);
            
            $lines = explode("\n", $white_ips);
            $valid_lines = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                // Keep comments
                if (strpos($line, '#') === 0) {
                    $valid_lines[] = $line;
                    continue;
                }
                
                // Clean and validate IP/range
                $clean_line = preg_replace('/[^a-zA-Z0-9:.\/#\-]/', '', $line);
                if (!empty($clean_line)) {
                    $valid_lines[] = $clean_line;
                }
            }
            
            // Save to file
            file_put_contents($this->custom_white_file, implode("\n", $valid_lines), LOCK_EX);
            if (is_writable($this->custom_white_file)) {
                chmod($this->custom_white_file, 0644);
            }
            
            wp_cache_delete('bot_killer_blocklist');
            
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf(__('Whitelist saved successfully! %d entries stored.', 'bot-killer'), count($valid_lines)) . 
                 '</p></div>';
        }
    }
    
// Handle ASN List save - store raw text with comments
if (isset($_POST['save_asn_list']) && check_admin_referer('bot_killer_asn_action', 'bot_killer_asn_nonce')) {
    if (isset($_POST['asn_raw'])) {
        $asn_raw = wp_unslash($_POST['asn_raw']);
        $asn_raw = sanitize_textarea_field($asn_raw);
        
        // Store the raw text exactly as entered
        update_option('bot_killer_asn_raw', $asn_raw);
        update_option('bot_killer_asn_last_updated', time());
        
        // Also parse and store clean ASNs for blocking
        $lines = explode("\n", $asn_raw);
        $clean_asns = [];
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            
            // Remove inline comments
            $number_part = strpos($line, '#') !== false ? substr($line, 0, strpos($line, '#')) : $line;
            
            // Extract only numbers
            $asn = preg_replace('/[^0-9]/', '', $number_part);
            
            if (!empty($asn) && is_numeric($asn) && $asn > 0 && $asn < 1000000) {
                $clean_asns[] = $asn;
            }
        }
        
        // Remove duplicates
        $clean_asns = array_unique($clean_asns);
        
        // Store clean ASNs for blocking logic
        update_option('bot_killer_blocked_asns', $clean_asns);
        
        echo '<div class="notice notice-success is-dismissible"><p>' . 
             sprintf(__('ASN list saved successfully! %d ASNs stored.', 'bot-killer'), count($clean_asns)) . 
             '</p></div>';
        
        // Refresh for display
        $blocked_asns = $clean_asns;
    }
}
    
    // Handle combined save (for backward compatibility)
    if (isset($_POST['save_ip_lists']) && check_admin_referer('bot_killer_ip_lists')) {
        // Blocklist
        if (isset($_POST['blocklist_ips'])) {
            $block_ips = wp_unslash($_POST['blocklist_ips']);
            $block_ips = str_replace(array("\r\n", "\r"), "\n", $block_ips);
            $block_ips = sanitize_textarea_field($block_ips);
            
            $lines = explode("\n", $block_ips);
            $valid_lines = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (strpos($line, '#') === 0) {
                    $valid_lines[] = $line;
                    continue;
                }
                $clean_line = preg_replace('/[^a-zA-Z0-9:.\/#\-]/', '', $line);
                if (!empty($clean_line)) {
                    $valid_lines[] = $clean_line;
                }
            }
            
            file_put_contents($this->custom_block_file, implode("\n", $valid_lines), LOCK_EX);
        }
        
        // Whitelist
        if (isset($_POST['whitelist_ips'])) {
            $white_ips = wp_unslash($_POST['whitelist_ips']);
            $white_ips = str_replace(array("\r\n", "\r"), "\n", $white_ips);
            $white_ips = sanitize_textarea_field($white_ips);
            
            $lines = explode("\n", $white_ips);
            $valid_lines = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (strpos($line, '#') === 0) {
                    $valid_lines[] = $line;
                    continue;
                }
                $clean_line = preg_replace('/[^a-zA-Z0-9:.\/#\-]/', '', $line);
                if (!empty($clean_line)) {
                    $valid_lines[] = $clean_line;
                }
            }
            
            file_put_contents($this->custom_white_file, implode("\n", $valid_lines), LOCK_EX);
        }
        
        // ASN List
        if (isset($_POST['bot_killer_blocked_asns'])) {
            $asn_text = wp_unslash($_POST['bot_killer_blocked_asns']);
            $asn_text = str_replace(array("\r\n", "\r"), "\n", $asn_text);
            
            $lines = explode("\n", $asn_text);
            $clean_asns = [];
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                if (strpos($line, '#') !== false) {
                    $line = trim(substr($line, 0, strpos($line, '#')));
                    if (empty($line)) continue;
                }
                $asn = preg_replace('/[^0-9]/', '', $line);
                if (!empty($asn) && is_numeric($asn) && $asn > 0 && $asn < 1000000) {
                    $clean_asns[] = $asn;
                }
            }
            
            update_option('bot_killer_blocked_asns', $clean_asns);
            update_option('bot_killer_asn_last_updated', time());
        }
        
        wp_cache_delete('bot_killer_blocklist');
        
        echo '<div class="notice notice-success is-dismissible"><p>' . 
             __('IP lists and ASN list saved successfully!', 'bot-killer') . 
             '</p></div>';
    }
    
    // After all settings are saved (at the end of your save handlers)
    if (isset($_POST['save_settings']) || isset($_POST['save_ip_lists']) || 
        isset($_POST['save_blocklist']) || isset($_POST['save_whitelist']) || 
        isset($_POST['save_asn_list'])) {
        update_option('bot_killer_last_save_time', time());
    }
    
    // Get all settings for display
    $timezone_offset = get_option('bot_killer_timezone', '+02:00');
    $custom_rules_enabled = get_option('bot_killer_custom_rules_enabled', 0);
    $custom_rules = get_option('bot_killer_custom_rules', '');
    $unblock_hours = get_option('bot_killer_unblock_hours', 24);
    $block_tor = get_option('bot_killer_block_tor', 1);
    $blocked_asns = get_option('bot_killer_blocked_asns', []);
    
    $max_log_size = get_option('bot_killer_max_log_size', 10);
    if ($max_log_size > 10000) {
        $max_log_size = intval($max_log_size / (1024 * 1024));
        update_option('bot_killer_max_log_size', $max_log_size);
    }
    $max_log_size = max(1, min(1000, intval($max_log_size)));
    
    $allowed_countries = get_option('bot_killer_allowed_countries', array());
    $block_unknown = get_option('bot_killer_block_unknown_country', 0);
    $disable_geoip = get_option('bot_killer_disable_geoip', 0);
    $geoip_cache_hours = get_option('bot_killer_geoip_cache_hours', 24);
    $geoip_fallback = get_option('bot_killer_geoip_fallback', 1);
    $geoip_service = get_option('bot_killer_geoip_service', 'freegeoip');
    $block_out_of_stock = get_option('bot_killer_block_out_of_stock', 1);
    $block_headless = get_option('bot_killer_block_headless', 1);
    $block_browser_integrity = get_option('bot_killer_block_browser_integrity', 1);
    
    // Read current IP lists from files
    $block_ips = file_exists($this->custom_block_file) ? file_get_contents($this->custom_block_file) : '';
    $white_ips = file_exists($this->custom_white_file) ? file_get_contents($this->custom_white_file) : '';
    
    // Count active entries
    $custom_blocked_count = 0;
    if (file_exists($this->custom_block_file)) {
        $custom_blocks = file($this->custom_block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($custom_blocks !== false) {
            foreach ($custom_blocks as $block) {
                if (strpos($block, '#') !== 0 && !empty(trim($block))) {
                    $custom_blocked_count++;
                }
            }
        }
    }
    
    $custom_whitelist_count = 0;
    if (file_exists($this->custom_white_file)) {
        $custom_whites = file($this->custom_white_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($custom_whites !== false) {
            foreach ($custom_whites as $white) {
                if (strpos($white, '#') !== 0 && !empty(trim($white))) {
                    $custom_whitelist_count++;
                }
            }
        }
    }
    
    $google_count = !empty($this->google_ips) ? count($this->google_ips) : 0;
    $bing_count = !empty($this->bing_ips) ? count($this->bing_ips) : 0;
    $cloudflare_v4 = !empty($this->cloudflare_ips['v4']) ? count($this->cloudflare_ips['v4']) : 0;
    $cloudflare_v6 = !empty($this->cloudflare_ips['v6']) ? count($this->cloudflare_ips['v6']) : 0;
    
    include BOTKILLER_PLUGIN_DIR . 'includes/views/admin-settings.php';
}

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'bot-killer'));
        }
        
        $this->timezone_offset = get_option('bot_killer_timezone', '+02:00');
        $this->set_timezone();
        $this->maybe_cleanup_expired_blocks();
        
        if (isset($_POST['unblock_ip']) && check_admin_referer('unblock_action')) {
            $ip_to_unblock = sanitize_text_field($_POST['unblock_ip']);
            if (file_exists($this->block_file)) {
                $blocked = file($this->block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($blocked !== false) {
                    $blocked = array_diff($blocked, array($ip_to_unblock));
                    file_put_contents($this->block_file, implode("\n", $blocked) . "\n", LOCK_EX);
                    if (is_writable($this->block_file)) {
                        chmod($this->block_file, 0644);
                    }
                }
                $this->remove_block_meta($ip_to_unblock);
                $this->log_action($ip_to_unblock, __("manually unblocked by admin", 'bot-killer'));
                wp_cache_delete('bot_killer_blocklist');
                echo '<div class="notice notice-success"><p>' . sprintf(__('ip %s unblocked!', 'bot-killer'), esc_html($ip_to_unblock)) . '</p></div>';
            }
        }
        
        if (isset($_POST['clear_all']) && check_admin_referer('clear_all')) {
            file_put_contents($this->block_file, "", LOCK_EX);
            if (is_writable($this->block_file)) {
                chmod($this->block_file, 0644);
            }
            file_put_contents($this->block_meta_file, wp_json_encode([]), LOCK_EX);
            if (is_writable($this->block_meta_file)) {
                chmod($this->block_meta_file, 0644);
            }
            $this->log_action("ALL", __("all auto-blocked ips manually cleared by admin", 'bot-killer'));
            wp_cache_delete('bot_killer_blocklist');
            echo '<div class="notice notice-success"><p>' . __('all auto-blocked ips unblocked!', 'bot-killer') . '</p></div>';
        }
        
        if (isset($_POST['clear_log']) && check_admin_referer('clear_log')) {
            $clear_time = $this->get_current_time();
            $header = "=== " . __('LOG CLEARED', 'bot-killer') . " at {$clear_time} ===\n================================================\n";
            file_put_contents($this->log_file, $header, LOCK_EX);
            if (is_writable($this->log_file)) {
                chmod($this->log_file, 0644);
            }
            echo '<div class="notice notice-success"><p>' . __('log cleared!', 'bot-killer') . '</p></div>';
        }
        
        $blocked_ips = file_exists($this->block_file) ? file($this->block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
        if ($blocked_ips === false) $blocked_ips = array();
        $block_meta = $this->get_block_meta();
        
        $blocked_asns = get_option('bot_killer_blocked_asns', []);
        
        $custom_blocked_count = 0;
        if (file_exists($this->custom_block_file)) {
            $custom_blocks = file($this->custom_block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($custom_blocks !== false) {
                foreach ($custom_blocks as $block) {
                    if (strpos($block, '#') !== 0 && !empty(trim($block))) $custom_blocked_count++;
                }
            }
        }
        
        $custom_whitelist_count = 0;
        if (file_exists($this->custom_white_file)) {
            $custom_whites = file($this->custom_white_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($custom_whites !== false) {
                foreach ($custom_whites as $white) {
                    if (strpos($white, '#') !== 0 && !empty(trim($white))) $custom_whitelist_count++;
                }
            }
        }
        
        $custom_rules_enabled = get_option('bot_killer_custom_rules_enabled', 0);
        $unblock_hours = get_option('bot_killer_unblock_hours', 24);
        $block_out_of_stock = get_option('bot_killer_block_out_of_stock', 1);
        $block_browser_integrity = get_option('bot_killer_block_browser_integrity', 1);
        $block_headless = get_option('bot_killer_block_headless', 1);
        $block_tor = get_option('bot_killer_block_tor', 1);
        // Get ASN list
        $blocked_asns = get_option('bot_killer_blocked_asns', []);
        
        $block_ips = file_exists($this->custom_block_file) ? file_get_contents($this->custom_block_file) : '';
        $white_ips = file_exists($this->custom_white_file) ? file_get_contents($this->custom_white_file) : '';
        $allowed_countries = get_option('bot_killer_allowed_countries', array());
        
        $log_content = file_exists($this->log_file) ? file_get_contents($this->log_file) : __('No log yet', 'bot-killer');
        $log_lines = explode("\n", trim($log_content));
        $log_lines = array_reverse($log_lines);
        $log_lines = array_slice($log_lines, 0, 600);
        
        include BOTKILLER_PLUGIN_DIR . 'includes/views/admin-dashboard.php';
    }



    public function maybe_cleanup_expired_blocks() {
        $last_cleanup = get_option('bot_killer_last_cleanup', 0);
        if (time() - $last_cleanup > 3600) {
            $this->cleanup_expired_blocks();
            update_option('bot_killer_last_cleanup', time());
        }
    }
}