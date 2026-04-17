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
    private $cache_group = 'bot_killer';
    private $block_meta_cache = null;

public function __construct() {   
    static $instance_loaded = false;
    if ($instance_loaded) {
        return;
    }
    
    $this->cache_group = 'bot_killer';
     
    $this->setup_secure_directory();
    $this->set_default_options();
    
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
    add_action('wp_ajax_bot_killer_clear_geoip_cache', array($this, 'ajax_clear_geoip_cache'));
    add_action('wp_ajax_bot_killer_toggle_debug_mode', array($this, 'ajax_toggle_debug_mode'));
    add_action('wp_ajax_bot_killer_export_blocked_ips', array($this, 'ajax_export_blocked_ips'));
    
    add_action('wp_ajax_bot_killer_test_geoip', array($this, 'ajax_test_geoip'));

    register_deactivation_hook(BOTKILLER_PLUGIN_FILE, array($this, 'deactivate'));
    add_action('wp_ajax_bot_killer_update_search_engines', array($this, 'ajax_update_search_engines'));
    add_action('woocommerce_thankyou', array($this, 'track_order'), 10, 1);
    add_action('woocommerce_order_status_completed', array($this, 'track_order'), 10, 1);
    add_action('woocommerce_cart_item_removed', array($this, 'track_remove_from_cart'), 10, 2);
    add_action('wp_ajax_bot_killer_update_tor_nodes', array($this, 'ajax_update_tor_nodes'));
    add_action('wp_ajax_bot_killer_export_csv', array($this, 'ajax_export_csv'));
    
    // Schedule events
    if (!wp_next_scheduled('bot_killer_update_tor_nodes')) {
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
        
        $this->log_file = $this->upload_dir . 'bot-killer-log.json';
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
    $body = $this->safe_api_request('https://www.cloudflare.com/ips-v4', array('timeout' => 15), 'cloudflare_v4');
    
    if ($body !== false) {
        $body = trim($body);
        if (!empty($body)) {
            $ips['v4'] = array_filter(explode("\n", $body));
            $success = true;
        }
    }
    
    // Fetch IPv6 ranges
    $body = $this->safe_api_request('https://www.cloudflare.com/ips-v6', array('timeout' => 15), 'cloudflare_v6');
    
    if ($body !== false) {
        $body = trim($body);
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
        
        $this->log_json('SYSTEM', 'Using fallback Cloudflare IPs - fetch failed', 'system', 'log-default');
        $success = true;
    } else {
        $v4_count = count($ips['v4']);
        $v6_count = count($ips['v6']);
        
        // Log based on source
        if ($source === 'manual') {
            $this->log_json('SYSTEM', sprintf('Cloudflare IPs manually updated: %d IPv4, %d IPv6 ranges', $v4_count, $v6_count), 'system', 'log-default');
        } else {
            $this->log_json('SYSTEM', sprintf('Cloudflare IPs updated via cron: %d IPv4, %d IPv6 ranges', $v4_count, $v6_count), 'system', 'log-default');
        }
    }
    
    $this->cloudflare_ips = $ips;
    set_transient('bot_killer_cloudflare_ips', $ips, WEEK_IN_SECONDS);
    
    return $success;
}

/**
 * AJAX handler for testing GeoIP service
 */
public function ajax_test_geoip() {
    // Verify nonce
    if (!check_ajax_referer('bot_killer_ajax', 'nonce', false)) {
        wp_send_json_error(['message' => __('Invalid security token', 'bot-killer')]);
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Permission denied', 'bot-killer')]);
    }
    
    $primary_url = isset($_POST['primary_url']) ? sanitize_text_field($_POST['primary_url']) : '';
    $fallback_url = isset($_POST['fallback_url']) ? sanitize_text_field($_POST['fallback_url']) : '';
    $fallback_enabled = isset($_POST['fallback_enabled']) ? (int)$_POST['fallback_enabled'] : 0;
    
    $test_ip = '8.8.8.8';
    $result = [
        'primary_result' => null,
        'fallback_result' => null
    ];
    
    // Test primary service
    if (!empty($primary_url)) {
        $location = $this->geoip_lookup_by_url($test_ip, $primary_url);
        if ($location && isset($location['country_code']) && !empty($location['country_code'])) {
            $result['primary_result'] = sprintf(
                '✅ %s - %s (%s) | ASN: %s',
                $location['country_code'],
                $location['city'] ?? 'unknown',
                $location['service'] ?? 'unknown',
                $location['asn'] ?? 'N/A'
            );
        } else {
            $result['primary_result'] = '❌ ' . __('Failed to get location', 'bot-killer');
        }
    } else {
        $result['primary_result'] = '❌ ' . __('URL is empty', 'bot-killer');
    }
    
    // Test fallback service if enabled
    if ($fallback_enabled && !empty($fallback_url)) {
        $location = $this->geoip_lookup_by_url($test_ip, $fallback_url);
        if ($location && isset($location['country_code']) && !empty($location['country_code'])) {
            $result['fallback_result'] = sprintf(
                '✅ %s - %s (%s) | ASN: %s',
                $location['country_code'],
                $location['city'] ?? 'unknown',
                $location['service'] ?? 'unknown',
                $location['asn'] ?? 'N/A'
            );
        } else {
            $result['fallback_result'] = '❌ ' . __('Failed to get location', 'bot-killer');
        }
    } elseif ($fallback_enabled && empty($fallback_url)) {
        $result['fallback_result'] = '⚠️ ' . __('Fallback URL is empty', 'bot-killer');
    } else {
        $result['fallback_result'] = 'ℹ️ ' . __('Fallback disabled', 'bot-killer');
    }
    
    wp_send_json_success($result);
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

public function ajax_export_csv() {
    if (!check_ajax_referer('bot_killer_export_csv', 'nonce', false)) {
        wp_die(__('Invalid security token', 'bot-killer'), '', array('response' => 403));
    }
    
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission denied', 'bot-killer'), '', array('response' => 403));
    }
    
    $log_entries = [];
    if (file_exists($this->log_file)) {
        $content = file_get_contents($this->log_file);
        $lines = explode("\n", trim($content));
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $entry = json_decode($line, true);
            if ($entry) {
                $log_entries[] = $entry;
            }
        }
    }
    
    $log_entries = array_reverse($log_entries);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="bot-killer-log-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Time', 'IP', 'Type', 'Message', 'User Agent']);
    
    foreach ($log_entries as $entry) {
        fputcsv($output, [
            $entry['time'] ?? '',
            $entry['ip'] ?? '',
            $entry['type'] ?? '',
            $entry['message'] ?? '',
            $entry['user_agent'] ?? ''
        ]);
    }
    
    fclose($output);
    wp_die();
}

public function ajax_export_blocked_ips() {
    if (!check_ajax_referer('bot_killer_export_ips', 'nonce', false)) {
        wp_die(__('Invalid security token', 'bot-killer'), '', array('response' => 403));
    }
    
    if (!current_user_can('manage_options')) {
        wp_die(__('Permission denied', 'bot-killer'), '', array('response' => 403));
    }
    
    $blocked_ips = file_exists($this->block_file) ? 
        file($this->block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    
    if (empty($blocked_ips)) {
        wp_die(__('No blocked IPs found', 'bot-killer'), '', array('response' => 404));
    }
    
    $meta = $this->get_block_meta();
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="auto-blocked-ips-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Headers with all fields
    fputcsv($output, [
        'IP',
        'Country',
        'City',
        'Blocked At',
        'Unblocks At',
        'Reason',
        'User Agent',
        'ASN',
        'AS Name',
        'Bot Name',
        'Verification Method',
        'Block Source'
    ]);
    
    foreach ($blocked_ips as $ip) {
        $ip = trim($ip);
        if (empty($ip)) continue;
        
        $info = isset($meta[$ip]) ? $meta[$ip] : null;
        
        $country = '';
        $city = '';
        $blocked_at = '';
        $unblocks_at = '';
        $reason = '';
        $user_agent = '';
        $asn = '';
        $as_name = '';
        $bot_name = '';
        $verification_method = '';
        $block_source = '';
        
        if ($info) {
            $geo = $info['geo'] ?? null;
            $country = $geo['country_code'] ?? '';
            $city = $geo['city'] ?? '';
            $blocked_at = $info['blocked_at_readable'] ?? '';
            $unblocks_at = $info['unblock_at_readable'] ?? '';
            $reason = $info['reason'] ?? '';
            $user_agent = $info['user_agent'] ?? '';
            $asn = $info['asn'] ?? '';
            $as_name = $info['as_name'] ?? '';
            $bot_name = $info['bot_name'] ?? '';
            $verification_method = $info['verification_method'] ?? '';
            $block_source = $info['block_source'] ?? '';
        }
        
        fputcsv($output, [
            $ip,
            $country,
            $city,
            $blocked_at,
            $unblocks_at,
            $reason,
            $user_agent,
            $asn,
            $as_name,
            $bot_name,
            $verification_method,
            $block_source
        ]);
    }
    
    fclose($output);
    wp_die();
}

public function ajax_clear_geoip_cache() {
    // Verify nonce and capabilities
    if (!check_ajax_referer('bot_killer_ajax', 'nonce', false)) {
        wp_send_json_error(__('Invalid security token. Please refresh the page and try again.', 'bot-killer'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'bot-killer'));
    }
    
    global $wpdb;
    
    // Delete all GeoIP transients
    $count = $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_bot_killer_geo_%' 
         OR option_name LIKE '_transient_timeout_bot_killer_geo_%'"
    );
    
    if ($count === false) {
        wp_send_json_error(__('Failed to clear GeoIP cache.', 'bot-killer'));
    }
    
    $this->log_json('SYSTEM', sprintf('GeoIP cache cleared: %d entries deleted', $count), 'system', 'log-default');
    
    wp_send_json_success(sprintf(__('GeoIP cache cleared! %d entries deleted.', 'bot-killer'), $count));
}

public function ajax_toggle_debug_mode() {
    if (!check_ajax_referer('bot_killer_ajax', 'nonce', false)) {
        wp_send_json_error(__('Invalid security token.', 'bot-killer'));
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('You do not have permission to perform this action.', 'bot-killer'));
    }
    
    $enabled = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;
    update_option('bot_killer_debug_mode', $enabled);
    
    $this->log_json('SYSTEM', 'Debug mode ' . ($enabled ? 'enabled' : 'disabled'), 'system', 'log-default');
    
    wp_send_json_success(array('enabled' => $enabled));
}

private function fetch_google_ips($source = 'cron') {
    $ips = [];
    
    $urls = [
        'https://developers.google.com/static/search/apis/ipranges/googlebot.json',
        'https://developers.google.com/static/search/apis/ipranges/common-crawlers.json',
    ];
    
    foreach ($urls as $url) {
        $body = $this->safe_api_request($url, array('timeout' => 10), 'google_ips');
        
        if ($body !== false) {
            $data = json_decode($body, true);
            
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
            $this->log_json('SYSTEM', sprintf('Google IP ranges manually updated: %d ranges', count($unique_ips)), 'system', 'log-default');
        } else {
            $this->log_json('SYSTEM', sprintf('Google IP ranges updated via cron: %d ranges', count($unique_ips)), 'system', 'log-default');
        }
    } else {
        $this->log_json('SYSTEM', 'Google IP ranges update failed - using fallback', 'system', 'log-default');
    }
    
    return $unique_ips;
}

private function fetch_bing_ips($source = 'cron') {
    $ips = [];
    
    $body = $this->safe_api_request('https://www.bing.com/toolbox/bingbot.json', array('timeout' => 10), 'bing_ips');
    
    if ($body !== false) {
        $data = json_decode($body, true);
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
        
        $this->log_json('SYSTEM', 'Bing IP ranges update failed - using fallback', 'system', 'log-default');
    } else {
        // Log based on source
        if ($source === 'manual') {
            $this->log_json('SYSTEM', sprintf('Bing IP ranges manually updated: %d ranges', count($ips)), 'system', 'log-default');
        } else {
            $this->log_json('SYSTEM', sprintf('Bing IP ranges updated via cron: %d ranges', count($ips)), 'system', 'log-default');
        }
    }
    
    return $ips;
}

private function reverse_dns_lookup($ip, $timeout = 8) {
    $original_timeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', $timeout);
    
    $hostname = @gethostbyaddr($ip);
    
    ini_set('default_socket_timeout', $original_timeout);
    
    return ($hostname !== false && $hostname !== $ip) ? $hostname : false;
}

private function forward_dns_lookup($hostname, $timeout = 8) {
    $original_timeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', $timeout);
    
    $ips = @gethostbynamel($hostname);
    
    ini_set('default_socket_timeout', $original_timeout);
    
    return $ips;
}
    
/**
 * Check if User-Agent is an allowed multi-bot (facebook+twitter, facebook+linkedin, facebook+slack)
 * @param string $ua
 * @return bool
 */
private function is_allowed_multi_bot($ua) {
    if (empty($ua)) {
        return false;
    }

    $ua = strtolower($ua);

    $bot_groups = [
        'facebook' => ['facebookexternalhit', 'facebot'],
        'twitter'  => ['twitterbot'],
        'linkedin' => ['linkedinbot'],
        'slack'    => ['slackbot', 'slack-imgproxy'],
    ];

    $matched_groups = [];

    foreach ($bot_groups as $group => $signatures) {
        foreach ($signatures as $sig) {
            if (strpos($ua, $sig) !== false) {
                $matched_groups[$group] = true;
                break;
            }
        }
    }

    if (count($matched_groups) < 2) {
        return false;
    }

    // Allowed combos (same as in is_multi_bot_ua)
    $allowed_combos = [
        ['facebook', 'twitter'],
        ['facebook', 'linkedin'],
        ['facebook', 'slack'],
    ];

    $matched_keys = array_keys($matched_groups);

    foreach ($allowed_combos as $combo) {
        if (count(array_intersect($matched_keys, $combo)) === count($combo)) {
            return true;
        }
    }

    return false;
}

private function is_multi_bot_ua($ua) {
    if (empty($ua)) {
        return false;
    }

    $ua = strtolower($ua);
    
    // Normalize: TelegramBot (like TwitterBot) should be treated as just TelegramBot
    if (strpos($ua, 'telegrambot') !== false && preg_match('/\(like\s+\w+bot\)/i', $ua)) {
        $ua = preg_replace('/\s*\(like\s+\w+bot\)/i', '', $ua);
    }

    $bot_groups = [
        'facebook' => ['facebookexternalhit', 'facebot'],
        'twitter'  => ['twitterbot'],
        'linkedin' => ['linkedinbot'],
        'slack'    => ['slackbot', 'slack-imgproxy'],
        'discord'  => ['discordbot'],
        'telegram' => ['telegrambot'],
        'whatsapp' => ['whatsapp'],
        'pinterest'=> ['pinterestbot'],
        'google'   => ['googlebot'],
        'bing'     => ['bingbot'],
    ];

    $matched_groups = [];

    foreach ($bot_groups as $group => $signatures) {
        foreach ($signatures as $sig) {
            if (strpos($ua, $sig) !== false) {
                $matched_groups[$group] = true;
                break;
            }
        }
    }

    if (count($matched_groups) < 2) {
        return false;
    }

    // ========= ALLOWED COMBOS (пропускаємо) =========
    $allowed_combos = [
        ['facebook', 'twitter'],
        ['facebook', 'linkedin'],
        ['facebook', 'slack'],
    ];

    $matched_keys = array_keys($matched_groups);
    
    foreach ($allowed_combos as $combo) {
        if (count(array_intersect($matched_keys, $combo)) === count($combo)) {
            return false;
        }
    }

    // ========= REAL BROWSER CHECK =========
    $is_real_browser =
        strpos($ua, 'mozilla/') !== false &&
        (
            strpos($ua, 'chrome/') !== false ||
            strpos($ua, 'safari/') !== false ||
            strpos($ua, 'firefox/') !== false ||
            strpos($ua, 'edg/') !== false
        ) &&
        strpos($ua, 'bot') === false &&
        strpos($ua, 'crawler') === false &&
        strpos($ua, 'spider') === false;

    if ($is_real_browser) {
        return false;
    }

    return true;
}

private function get_cart_interacting_bot($ip, $user_agent) {
    // ========== GET LOGGING SETTINGS ==========
    $log_app_user = get_option('bot_killer_log_app_user', 0);
    $log_ai_user = get_option('bot_killer_log_ai_user', 0);
    $log_browser_user = get_option('bot_killer_log_browser_user', 0);
    $log_browser_limit_country = get_option('bot_killer_log_browser_limit_country', 0);
    $log_browser_ttl = get_option('bot_killer_log_browser_ttl', 4);
    
    // ========== CHECK IF IP IS IN CUSTOM BLOCKLIST ==========
    if ($this->is_ip_in_custom_blocklist($ip)) {
        return false;
    }

    // ========== CHECK IF IP IS ALREADY BLOCKED ==========
    if ($this->is_ip_blocked($ip)) {
        return false; 
    }

    //$ua = $user_agent;

// ========== REAL USERS ==========

// Multi UA user
if ($this->is_multi_bot_ua($user_agent)) {
    $this->log_json($ip, "MULTI-BOT UA detected", 'suspicious', 'log-cart-user');
    return false;
}

// OpenAI (ChatGPT user) - with IP verification and rate limiting
if (stripos($user_agent, 'ChatGPT-User') !== false && stripos($user_agent, 'GPTBot') === false && stripos($user_agent, 'OAI-SearchBot') === false) {
    
    $openai_ranges = $this->get_openai_ip_ranges();
    $ip_valid = false;
    
    foreach ($openai_ranges as $range) {
        if ($this->ip_in_range($ip, $range)) {
            $ip_valid = true;
            break;
        }
    }
    
    if ($ip_valid) {
        $log_key = 'bot_killer_openai_logged_' . md5($ip);
        $should_log = !get_transient($log_key);
        
        if ($should_log && $log_ai_user) {
            $this->log_json($ip, "AI USER: OpenAI (ChatGPT) - verified", 'user_detected', 'log-cart-user');
            set_transient($log_key, true, $ttl_seconds);
        }
        return false;
    } else {
        $this->block_ip($ip, "SPOOF ATTEMPT: Fake ChatGPT-User", 'openai', 'ip_mismatch', 'log-spoof-attempt');
        return 'openai';
    }
}

// Perplexity AI user - with IP verification and rate limiting
if (stripos($user_agent, 'Perplexity-User') !== false && stripos($user_agent, 'PerplexityBot') === false) {
    
    $perplexity_ranges = $this->get_perplexity_ip_ranges();
    $ip_valid = false;
    
    foreach ($perplexity_ranges as $range) {
        if ($this->ip_in_range($ip, $range)) {
            $ip_valid = true;
            break;
        }
    }
    
    if ($ip_valid) {
        $log_key = 'bot_killer_perplexity_logged_' . md5($ip);
        $should_log = !get_transient($log_key);
        
        if ($should_log && $log_ai_user) {
            $this->log_json($ip, "AI USER: Perplexity - verified", 'user_detected', 'log-cart-user');
            set_transient($log_key, true, $ttl_seconds);
        }
        return false;
    } else {
        $this->block_ip($ip, "SPOOF ATTEMPT: Fake Perplexity-User", 'perplexity', 'ip_mismatch', 'log-spoof-attempt');
        return 'perplexity';
    }
}

// WhatsApp user - with cumulative attempts and cooldown
if (stripos($user_agent, 'WhatsApp/') !== false) {
    
    // Cooldown check
    $cooldown_key = 'bot_killer_whatsapp_cooldown_' . md5($ip);
    if (get_transient($cooldown_key)) {
        $this->log_json($ip, "WHATSAPP user - blocked (cooldown active)", 'blocked', 'log-blocked');
        return false;
    }
    
    $asn_info = $this->get_asn_for_ip($ip);
    $is_meta_asn = ($asn_info && in_array($asn_info['asn'], ['32934', '63293']));
    
    $has_js = get_transient('bot_killer_js_detected_' . md5($ip));
    $has_cookies = !empty($_COOKIE);
    
    // Good: Meta ASN or JS/cookies present
    if ($is_meta_asn || $has_js || $has_cookies) {
        delete_transient('bot_killer_whatsapp_attempts_' . md5($ip));
        delete_transient('bot_killer_whatsapp_cooldown_' . md5($ip));
        if ($log_app_user) {
            $this->log_json($ip, "APP USER: WhatsApp - allowed", 'user_detected', 'log-cart-user');
        }
        return false;
    }
    
    // Cumulative attempts (never reset on reject)
    $attempts_key = 'bot_killer_whatsapp_attempts_' . md5($ip);
    $attempts = (int) get_transient($attempts_key);
    $attempts++;
    set_transient($attempts_key, $attempts, 60);
    
    if ($log_app_user) {
        $this->log_json($ip, "APP USER: WhatsApp - suspicious (attempt {$attempts}/5, no JS/cookies)", 'suspicious', 'log-cart-user');
    }
    
    if ($attempts >= 5) {
        $this->block_ip($ip, "WHATSAPP bot - no verification after {$attempts} attempts", 'whatsapp', 'no_js_cookies', 'log-spoof-attempt');
        set_transient($cooldown_key, 1, 300);
        return false;
    }
    
    return false;
}

// Viber user - logging only
if (stripos($user_agent, 'Viber/') !== false) {
    
    $has_js = get_transient('bot_killer_js_detected_' . md5($ip));
    $has_cookies = !empty($_COOKIE);
    
    $log_key = 'bot_killer_viber_logged_' . md5($ip);
    $should_log = !get_transient($log_key);
    
    if ($has_js || $has_cookies) {
        if ($should_log && $log_app_user) {
            $this->log_json($ip, "APP USER: Viber (in-app browser) - detected", 'user_detected', 'log-cart-user');
            set_transient($log_key, true, $ttl_seconds);
        }
    } else {
        if ($should_log && $log_app_user) {
            $this->log_json($ip, "VIBER preview bot - detected", 'bot_detected', 'log-cart-bot');
            set_transient($log_key, true, $ttl_seconds);
        }
    }
    
    return false;
}

// Claude AI user - with IP verification and rate limiting
if (stripos($user_agent, 'Claude-User') !== false && stripos($user_agent, 'bot') === false &&
    stripos($user_agent, 'crawler') === false && stripos($user_agent, 'spider') === false) {
    
    $claude_ranges = $this->get_anthropic_ip_ranges();
    $ip_valid = false;
    
    foreach ($claude_ranges as $range) {
        if ($this->ip_in_range($ip, $range)) {
            $ip_valid = true;
            break;
        }
    }
    
    if (!$ip_valid) {
        $asn_info = $this->get_asn_for_ip($ip);
        if ($asn_info && $asn_info['asn'] === '399358') {
            $ip_valid = true;
        }
    }
    
    if ($ip_valid) {
        $log_key = 'bot_killer_claude_logged_' . md5($ip);
        $should_log = !get_transient($log_key);
        
        if ($should_log && $log_ai_user) {
            $this->log_json($ip, "AI USER: Claude - verified", 'user_detected', 'log-cart-user');
            set_transient($log_key, true, $ttl_seconds);
        }
        return false;
    } else {
        $this->block_ip($ip, "SPOOF ATTEMPT: Fake Claude-User", 'claude', 'ip_mismatch', 'log-spoof-attempt');
        return 'claude';
    }
}

// Mistral AI user - low confidence (no IP verification available)
if (stripos($user_agent, 'MistralAI-User') !== false && stripos($user_agent, 'bot') === false &&
    stripos($user_agent, 'crawler') === false && stripos($user_agent, 'spider') === false) {
    
    $log_key = 'bot_killer_mistral_logged_' . md5($ip);
    $should_log = !get_transient($log_key);
    
    if ($should_log && $log_ai_user) {
        $this->log_json($ip, "AI USER: Mistral - allowed (low confidence)", 'user_detected', 'log-cart-user');
        set_transient($log_key, true, $ttl_seconds);
    }
    return false;
}

// Meta app user (Facebook/Instagram) - with rate limiting
if (strpos($user_agent, 'FBAN') !== false || 
    strpos($user_agent, 'FBAV') !== false || 
    stripos($user_agent, 'Instagram') !== false) {
    
    $log_key = 'bot_killer_meta_logged_' . md5($ip);
    $should_log = !get_transient($log_key);
    
    if ($should_log && $log_app_user) {
        $this->log_json($ip, "APP USER: Meta (Facebook/Instagram) - allowed", 'user_detected', 'log-cart-user');
        set_transient($log_key, true, $ttl_seconds);
    }
    
    return false;
}

// Telegram app user - with rate limiting
if (stripos($user_agent, 'Telegram') !== false && stripos($user_agent, 'TelegramBot') === false) {
    
    $log_key = 'bot_killer_telegram_logged_' . md5($ip);
    $should_log = !get_transient($log_key);
    
    if ($should_log && $log_app_user) {
        $this->log_json($ip, "APP USER: Telegram (in-app browser) - allowed", 'user_detected', 'log-cart-user');
        set_transient($log_key, true, $ttl_seconds);
    }
    
    return false;
}

// TikTok app user - with rate limiting and verification
if (stripos($user_agent, 'TikTok') !== false && stripos($user_agent, 'TikTokBot') === false && stripos($user_agent, 'TikTokSpider') === false) {
    
    $has_js = get_transient('bot_killer_js_detected_' . md5($ip));
    $has_cookies = !empty($_COOKIE);
    
    // Real user: has JS or cookies
    if ($has_js || $has_cookies) {
        if ($log_app_user) {
            $this->log_json($ip, "APP USER: TikTok (in-app browser) - verified", 'user_detected', 'log-cart-user');
        }
        return false;
    }
    
    // No JS/cookies - check rate limit
    $rate_key = 'bot_killer_tiktok_rate_' . md5($ip);
    $rate_count = get_transient($rate_key);
    
    if ($rate_count === false) {
        set_transient($rate_key, 1, 60);
        if ($log_app_user) {
            $this->log_json($ip, "APP USER: TikTok (in-app browser) - suspicious (attempt 1/5)", 'suspicious', 'log-cart-user');
        }
        return false;
    }
    
    $rate_count++;
    set_transient($rate_key, $rate_count, 60);
    
    if ($rate_count > 5) {
        $this->log_json($ip, "TIKTOK bot detected - too many requests ({$rate_count} in 60s, no JS/cookies)", 'rejected', 'log-rejected');
        return false;
    }
    
    if ($log_app_user) {
        $this->log_json($ip, "APP USER: TikTok (in-app browser) - suspicious (attempt {$rate_count}/5)", 'suspicious', 'log-cart-user');
    }
    
    return false;
}

// LinkedIn app user - with rate limiting
if (stripos($user_agent, 'LinkedInApp') !== false || stripos($user_agent, 'LinkedIn/') !== false ||
    stripos($user_agent, 'com.linkedin.android') !== false || stripos($user_agent, 'LIAPP') !== false) {
    
    $log_key = 'bot_killer_linkedin_logged_' . md5($ip);
    $should_log = !get_transient($log_key);
    
    if ($should_log && $log_app_user) {
        $this->log_json($ip, "APP USER: LinkedIn (in-app browser) - allowed", 'user_detected', 'log-cart-user');
        set_transient($log_key, true, $ttl_seconds);
    }
    
    return false;
}

// Generic mobile webview - log but continue checks (no early return)
if (stripos($user_agent, 'wv') !== false || stripos($user_agent, 'WebView') !== false) {
    
    $log_key = 'bot_killer_webview_logged_' . md5($ip);
    $should_log = !get_transient($log_key);
    
    if ($should_log && $log_app_user) {
        $this->log_json($ip, "APP USER: generic WebView - detected.", 'user_detected', 'log-cart-user');
        set_transient($log_key, true, $ttl_seconds);
    }
    
    // Continue to bot detection checks below
}
    
// 2. Facebook crawler (must have ASN 32934 or 63293)
$is_allowed_multi = $this->is_allowed_multi_bot($user_agent);
$is_ipv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);

if ((stripos($ua, 'facebookexternalhit') !== false || stripos($ua, 'Facebot') !== false) && !$is_allowed_multi) {

    $asn_info = $this->get_asn_for_ip($ip);
    $asn = $asn_info ? $asn_info['asn'] : null;
    $asn_text = $asn ? " (ASN: {$asn})" : '';

    $is_mobile = $this->is_mobile_browser($ua);
    $valid_asn = ['32934', '63293'];
    
    // For IPv6, skip ASN check and rely on IP ranges
    if ($is_ipv6) {
        $facebook_ranges = $this->get_facebook_ip_ranges();
        $ip_valid = false;
        foreach ($facebook_ranges as $range) {
            if ($this->ip_in_range($ip, $range)) {
                $ip_valid = true;
                break;
            }
        }
        
        if ($ip_valid) {
            $this->log_json($ip, "FACEBOOK crawler - verified (IPv6 range)", 'bot_detected', 'log-cart-bot');
            return 'facebook';
        } else {
            $this->log_json($ip, "SPOOF ATTEMPT HIGH: Facebook bot — IPv6 not in Meta ranges", 'spoof', 'log-spoof-attempt');
            $this->block_ip($ip, "SPOOF ATTEMPT HIGH: facebook", 'facebook', 'ipv6_range_mismatch', 'spoof_facebook');
            return false;
        }
    }
    
    // IPv4: check ASN
    if (!$asn || !in_array($asn, $valid_asn, true)) {
        if ($is_mobile) {
            $this->log_json($ip, "Facebook crawler UA on mobile device - allowed{$asn_text}", 'social_browser', 'log-cart-bot');
            return false;
        }
        $this->log_json($ip, "SPOOF ATTEMPT HIGH: Facebook bot — requires Meta IP ranges (AS32934/AS63293){$asn_text}", 'spoof', 'log-spoof-attempt');
        $this->block_ip($ip, "SPOOF ATTEMPT HIGH: facebook", 'facebook', 'asn_mismatch', 'spoof_facebook');
        return false;
    }

    $this->log_json($ip, "FACEBOOK crawler - verified (ASN 32934/63293){$asn_text}", 'bot_detected', 'log-cart-bot');
    return 'facebook';
}

// ========== AMAZONBOT ==========
if (stripos($ua, 'Amazonbot') !== false) {
    $hostname = $this->reverse_dns_lookup($ip, 5);
    
    if (!$hostname) {
        usleep(500000);
        $hostname = $this->reverse_dns_lookup($ip, 5);
    }
    
    if (!$hostname || strpos($hostname, '.crawl.amazonbot.amazon') === false) {
        $this->log_json($ip, "SPOOF ATTEMPT: Amazonbot — invalid DNS ({$hostname})", 'spoof', 'log-spoof-attempt');
        $this->block_ip($ip, "SPOOF ATTEMPT: Amazonbot", 'amazonbot', 'dns_mismatch', 'spoof_amazon');
        return false;
    }
    
    $forward_ips = $this->forward_dns_lookup($hostname, 5);
    if (!$forward_ips || !in_array($ip, $forward_ips)) {
        $this->log_json($ip, "SPOOF ATTEMPT: Amazonbot — forward DNS failed", 'spoof', 'log-spoof-attempt');
        $this->block_ip($ip, "SPOOF ATTEMPT: Amazonbot", 'amazonbot', 'dns_mismatch', 'spoof_amazon');
        return false;
    }
    
    $this->log_json($ip, "AMAZONBOT bot - verified (DNS)", 'bot_detected', 'log-cart-bot');
    return 'amazonbot';
}
    
    // ========== SKIP CART_BOTS FOR ALLOWED MULTI-BOTS ==========
    if ($this->is_allowed_multi_bot($user_agent)) {
        return false;
    }

    $cache_key = 'bot_killer_bot_type_' . md5($ip . $user_agent);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }
    
    // =============================================
    // USER-TRIGGERED GOOGLE SERVICES - ALLOW WITHOUT VERIFICATION
    // =============================================
    if (stripos($user_agent, 'Google-Read-Aloud') !== false) {
        $this->log_json($ip, "GOOGLE: Google-Read-Aloud - user-triggered service, allowed", 'user_detected', 'log-cart-bot');
        return false;
    }
    
    if (stripos($user_agent, 'Google-Site-Verification') !== false) {
        $this->log_json($ip, "GOOGLE: Google-Site-Verification - user-triggered service, allowed", 'user_detected', 'log-cart-bot');
        return false;
    }

        // =============================================
        // SEARCH ENGINES - STRICT CRAWLERS
        // =============================================
        $cart_bots = [
            'google' => [
                'agents' => [
                    'Googlebot', 'Googlebot-Image', 'Googlebot-News', 'Googlebot-Video', 'Googlebot-Mobile',
                    'Mediapartners-Google', 'FeedFetcher-Google', 'Googlebot/2.1', 'Googlebot/2.2'
                ],
                'dns' => ['.googlebot.com', '.google.com'],
                'ip_ranges' => $this->google_ips,
                'asn' => ['15169'],
                'type' => 'strict',
                'allow_js' => true,
                'require_verification' => true,
                'require_dns_only' => true,
                'spoof_risk' => 'high',
                'description' => 'Google bot — DNS or Google IP required'
            ],
            'google_extra' => [
                'agents' => [
                    'APIs-Google', 'DuplexWeb-Google', 'Google-PageRenderer', 'AdsBot-Google'
                ],
                'dns' => [],
                'ip_ranges' => $this->google_ips,
                'asn' => ['15169'],
                'type' => 'hybrid',
                'allow_js' => true,
                'require_verification' => false,
                'require_dns_only' => false,
                'spoof_risk' => 'medium',
                'description' => 'Google internal services — no strict verification'
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
                'require_dns_only' => true,
                'spoof_risk' => 'high',
                'description' => 'Bing bot — DNS + Microsoft IP required'
            ],

            'baidu' => [
                'agents' => ['Baiduspider', 'Baiduspider-image', 'Baiduspider-video', 'Baiduspider-news'],
                'ip_ranges' => $this->get_baidu_ip_ranges(),
                'asn' => ['55967', '37965'],
                'type' => 'strict',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'medium',
                'description' => 'Baidu bot — requires official Baidu IP ranges'
            ],
            'yandex' => [
                'agents' => ['YandexBot', 'YandexImages', 'YandexVideo', 'YandexNews', 'YandexMobileBot'],
                'dns' => ['.yandex.ru', '.yandex.net'],
                'ip_ranges' => $this->get_yandex_ip_ranges(),
                'asn' => ['13238', '208722'],
                'type' => 'strict',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => true,
                'spoof_risk' => 'medium',
                'description' => 'Yandex bot — requires yandex DNS or official IP ranges'
            ],
            'duckduckgo' => [
                'agents' => ['DuckDuckBot', 'DuckDuckGo-Favicons-Bot'],
                'ip_ranges' => $this->get_duckduckgo_ip_ranges(),
                'asn' => ['42729', '8075'],
                'type' => 'strict',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'medium',
                'description' => 'DuckDuckGo bot — IP/ASN verification'
            ],
            'seznam' => [
                'agents' => ['SeznamBot'],
                'ip_ranges' => $this->get_seznam_ip_ranges(),
                'asn' => ['43037'],
                'type' => 'strict',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'low',
                'description' => 'Seznam bot — requires official IP ranges'
            ],
            'petalbot' => [
                'agents' => ['PetalBot'],
                'ip_ranges' => $this->get_petalbot_ip_ranges(),
                'asn' => ['136907'],
                'type' => 'strict',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'low',
                'description' => 'PetalBot — requires official Huawei IP ranges'
            ],
        
            // =============================================
            // SOCIAL PREVIEW BOTS
            // =============================================
            
            'whatsapp' => [
                'agents' => ['WhatsApp-Preview', 'WhatsApp-LinkPreview'],
                'ip_ranges' => $this->get_facebook_ip_ranges(),
                'asn' => ['32934'],
                'type' => 'social',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'low',
                'description' => 'WhatsApp bot — requires Meta IP ranges (AS32934)'
            ],
            'linkedin' => [
                'agents' => ['LinkedInBot'],
                'ip_ranges' => $this->get_linkedin_ip_ranges(),
                'asn' => ['14413'],
                'type' => 'social',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'low',
                'description' => 'LinkedIn bot — requires AS14413 IP ranges'
            ],
            'pinterest' => [
                'agents' => ['Pinterestbot'],
                'ip_ranges' => $this->get_pinterest_ip_ranges(),
                'asn' => ['40027'],
                'type' => 'social',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'low',
                'description' => 'Pinterest bot — requires official IP ranges'
            ],
            'twitter' => [
                'agents' => ['Twitterbot'],
                'ip_ranges' => $this->get_twitter_ip_ranges(),
                'asn' => ['13414'],
                'type' => 'social',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'low',
                'description' => 'Twitter bot — requires AS13414 IP ranges'
            ],
            'discord' => [
                'agents' => ['Discordbot'],
                'ip_ranges' => $this->get_discord_ip_ranges(),
                'asn' => ['46475', '36978'],
                'type' => 'social',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
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
                'require_dns_only' => false,
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
                'require_dns_only' => false,
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
                'require_dns_only' => false,
                'spoof_risk' => 'low',
                'description' => 'Microsoft preview bot — requires Microsoft IP ranges (ASN 8075)'
            ],
        
            // =============================================
            // AI & HYBRID BOTS
            // =============================================
            'anthropic' => [
                'agents' => ['ClaudeBot', 'Claude-SearchBot'],
                'ip_ranges' => $this->get_anthropic_ip_ranges(),
                'asn' => [],
                'type' => 'hybrid',
                'allow_js' => true,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'medium',
                'description' => 'Claude bot — requires official IP ranges'
            ],
            'openai' => [
                'agents' => ['OAI-SearchBot', 'GPTBot'],
                'ip_ranges' => $this->get_openai_ip_ranges(),
                'asn' => [],
                'type' => 'hybrid',
                'allow_js' => true,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'medium',
                'description' => 'OpenAI crawler — requires official IP ranges'
            ],
            'perplexity' => [
                'agents' => ['PerplexityBot'],
                'ip_ranges' => $this->get_perplexity_ip_ranges(),
                'asn' => [],
                'type' => 'hybrid',
                'allow_js' => true,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'medium',
                'description' => 'Perplexity crawler — requires official IP ranges'
            ],
            'google_ai' => [
                'agents' => ['Google-Extended', 'Google-CloudVertexBot'],
                'dns' => ['.google.com', '.googlebot.com'],
                'ip_ranges' => $this->google_ips,
                'asn' => ['15169'],
                'type' => 'hybrid',
                'allow_js' => true,
                'require_verification' => true,
                'require_dns_only' => true,
                'spoof_risk' => 'high',
                'description' => 'Google AI bot — requires google DNS or Google IP ranges'
            ],
            'bytespider' => [
                'agents' => ['Bytespider'],
                'dns' => [],
                'ip_ranges' => $this->get_bytespider_ip_ranges(),
                'asn' => ['37963', '45090'],
                'type' => 'hybrid',
                'allow_js' => true,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'high',
                'description' => 'Bytespider — requires ByteDance IP ranges (ASN optional)'
            ],
            'tiktok' => [
                'agents' => ['TikTokBot', 'TikTokSpider'],
                'ip_ranges' => $this->get_tiktok_ip_ranges(),
                'asn' => ['396982', '45102'],
                'type' => 'hybrid',
                'allow_js' => true,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'medium',
                'description' => 'TikTok bot — requires official IP ranges (ASN optional)'
            ],
            'qwen' => [
                'agents' => ['QwenBot'],
                'dns' => ['.qwenlm.ai', '.aliyun.com'],
                'asn' => ['37963'],
                'type' => 'hybrid',
                'allow_js' => true,
                'require_verification' => true,
                'require_dns_only' => false, // ← ключове
                'spoof_risk' => 'medium',
                'description' => 'Qwen bot — requires qwenlm.ai DNS or AS37963'
            ],
            'metaai' => [
                'agents' => ['Meta-ExternalAgent', 'Meta-ExternalFetcher'],
                'ip_ranges' => $this->get_facebook_ip_ranges(),
                'asn' => ['32934', '63293'],
                'type' => 'hybrid',
                'allow_js' => true,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'high',
                'description' => 'Meta AI bot — requires Meta IP ranges (AS32934/AS63293)'
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
                'require_dns_only' => false,
                'spoof_risk' => 'low',
                'description' => 'Ahrefs bot — requires official IP ranges'
            ],
            'semrush' => [
                'agents' => ['SemrushBot', 'SemrushBot-SA'],
                'dns' => ['.semrush.com'],
                'ip_ranges' => $this->get_semrush_ip_ranges(),
                'asn' => ['203726'],
                'type' => 'strict',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'low',
                'description' => 'Semrush bot — IP/ASN/DNS required'
            ],
            'mj12bot' => [
                'agents' => ['MJ12bot'],
                'ip_ranges' => $this->get_mj12_ip_ranges(),
                'asn' => ['204734'],
                'type' => 'strict',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'high',
                'description' => 'MJ12 bot — requires official IP ranges'
            ],
            'dotbot' => [
                'agents' => ['DotBot'],
                'ip_ranges' => $this->get_dotbot_ip_ranges(),
                'asn' => ['26347'],
                'type' => 'strict',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'high',
                'description' => 'DotBot — requires official IP ranges'
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
                'require_dns_only' => false,
                'spoof_risk' => 'low',
                'description' => 'Cloudflare — requires AS13335 IP ranges'
            ],
            
            'ccbot' => [
                'agents' => ['CCBot'],
                'dns' => ['.crawl.commoncrawl.org'],
                'ip_ranges' => $this->get_ccbot_ip_ranges(),
                'asn' => ['16509'],
                'type' => 'strict',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => true,
                'spoof_risk' => 'low',
                'description' => 'CCBot — requires crawl.commoncrawl.org DNS'
            ],
            
            'applebot' => [
                'agents' => ['Applebot'],
                'dns' => ['.applebot.apple.com'],
                'ip_ranges' => $this->get_applebot_ip_ranges(),
                'asn' => ['714', '6185'],
                'type' => 'strict',
                'allow_js' => false,
                'require_verification' => true,
                'require_dns_only' => true,
                'spoof_risk' => 'low',
                'description' => 'Applebot — requires applebot.apple.com DNS'
            ],
            
            'youbot' => [
                'agents' => ['YouBot'],
                'dns' => [],
                'ip_ranges' => $this->get_youbot_ip_ranges(),
                'asn' => [],
                'type' => 'hybrid',
                'allow_js' => true,
                'require_verification' => true,
                'require_dns_only' => false,
                'spoof_risk' => 'medium',
                'description' => 'YouBot — requires official IP ranges'
            ],
        ];
 
foreach ($cart_bots as $bot_name => $bot_data) {
    foreach ($bot_data['agents'] as $agent) {
        if (stripos($user_agent, $agent) !== false) {              
            $dns_passed = false;
            $ip_range_passed = false;
            $asn_passed = false;
            
// ========== DNS verification ==========
if (isset($bot_data['dns']) && !empty($bot_data['dns'])) {
    $hostname = $this->reverse_dns_lookup($ip, 5);
    
    // Retry once if failed
    if (!$hostname) {
        usleep(500000); // 0.5 seconds delay
        $hostname = $this->reverse_dns_lookup($ip, 5);
    }
    
    if ($hostname) {
        foreach ($bot_data['dns'] as $suffix) {
            if (strpos($hostname, $suffix) !== false) {
                $forward_ips = $this->forward_dns_lookup($hostname, 5);
                if ($forward_ips && is_array($forward_ips) && in_array($ip, $forward_ips)) {
                    $dns_passed = true;
                } else {
                    $this->log_json($ip, "Forward DNS verification FAILED for {$hostname} - possible spoof", 'spoof', 'log-spoof-attempt');
                }
            }
        }
    }
}
            
            // ========== IP range verification ==========
            if (isset($bot_data['ip_ranges']) && !empty($bot_data['ip_ranges'])) {
                foreach ($bot_data['ip_ranges'] as $range) {
                    if ($this->ip_in_range($ip, $range)) {
                        $ip_range_passed = true;
                        break;
                    }
                }
            }
            
            // ========== ASN verification ==========
            if (isset($bot_data['asn']) && !empty($bot_data['asn'])) {
                $asn_info = $this->get_asn_for_ip($ip);
                if ($asn_info && isset($asn_info['asn']) && in_array($asn_info['asn'], $bot_data['asn'])) {
                    $asn_passed = true;
                }
            }

            // ========== CHECK RESULT BASED ON require_dns_only ==========
            if ($bot_data['require_dns_only'] === true) {
                // Special case: if ASN is empty and DNS passed, consider it verified
                $asn_empty = empty($bot_data['asn']);
                
                // Also trust IP range if DNS fails (protection against temporary DNS outages)
                if (($dns_passed && $asn_passed) || ($asn_empty && $dns_passed) || ($ip_range_passed)) {
                    $method = $dns_passed ? 'dns_forward' : 'ip_range';
                    $this->log_bot_detection($ip, $bot_name, $agent, $method);
                    set_transient($cache_key, $bot_name, 12 * HOUR_IN_SECONDS);
                    return $bot_name;
                } else {
                    $risk = $bot_data['spoof_risk'] ?? 'high';
                    
                    if ($risk === 'high') {
                        $this->log_json($ip, "SPOOF ATTEMPT HIGH: {$bot_data['description']}", 'spoof', 'log-spoof-attempt');
                        $this->block_ip($ip, "SPOOF ATTEMPT HIGH: {$bot_name}", $bot_name, 'verification_failed', 'spoof_bot');
                    } else {
                        $this->log_json($ip, "SPOOF ATTEMPT " . strtoupper($risk) . ": {$bot_data['description']}", 'spoof', 'log-spoof-attempt');
                        $this->log_json($ip, "{$bot_name} - rejected (unverified IP)", 'rejected', 'log-rejected');
                    }
                    set_transient($cache_key, false, HOUR_IN_SECONDS);
                    return false;
                }
            } else {
                // Стара логіка: хоча б одна перевірка пройшла
                if ($dns_passed || $ip_range_passed || $asn_passed) {
                    set_transient($cache_key, $bot_name, 12 * HOUR_IN_SECONDS);
                    return $bot_name;
                }
                
                // ========== CHECK FOR JS/COOKIES ==========
                $has_js = get_transient('bot_killer_js_detected_' . md5($ip));
                $has_cookies = !empty($_COOKIE);
                
                // For strict bots (except Google) - JS/cookies = SPOOF
                if ($bot_data['type'] === 'strict' && empty($bot_data['allow_js']) && ($has_js || $has_cookies)) {
                    $risk = $bot_data['spoof_risk'] ?? 'medium';
                    $this->log_json($ip, "SPOOF ATTEMPT " . strtoupper($risk) . ": {$bot_name} with JS/cookies", 'spoof', 'log-spoof-attempt');
                    set_transient($cache_key, false, HOUR_IN_SECONDS);
                    
                    if ($risk === 'high') {
                        $this->block_ip($ip, "SPOOF ATTEMPT HIGH: {$bot_name}", $bot_name, 'js_cookies_detected', 'spoof_bot');
                    }
                    return false;
                }
                
                if (isset($bot_data['require_verification']) && $bot_data['require_verification']) {
                    $asn_info = $this->get_asn_for_ip($ip);
                    $asn_text = $asn_info ? " (ASN: {$asn_info['asn']})" : '';
                    $risk = $bot_data['spoof_risk'] ?? 'medium';
                    
                    if ($risk === 'high') {
                        $this->log_json($ip, "SPOOF ATTEMPT HIGH: {$bot_data['description']}{$asn_text}", 'spoof', 'log-spoof-attempt');
                        $this->block_ip($ip, "SPOOF ATTEMPT HIGH: {$bot_name}", $bot_name, 'verification_failed', 'spoof_bot');
                    } else {
                        $this->log_json($ip, "REJECTED - " . strtoupper($risk) . " risk: {$bot_data['description']}{$asn_text}", 'rejected', 'log-rejected');
                    }
                    set_transient($cache_key, false, HOUR_IN_SECONDS);
                    return false;
                }
                
                // For bots without require_verification - just log
                $this->log_bot_detection($ip, $bot_name, $agent, 'user_agent_only');
                set_transient($cache_key, $bot_name, 2 * HOUR_IN_SECONDS);
                return $bot_name;
            }
        }
    }
}

    // =============================================
    // MOBILE/DESKTOP BROWSER DETECTION
    // =============================================
    $already_identified = false;
    
    $identified_patterns = [
        'FBAN', 'FBAV', 'Instagram', 'Telegram', 'TikTok', 'WhatsApp/', 'LinkedInApp', 'LinkedIn/', 'com.linkedin.android', 'LIAPP',
        'wv', 'WebView', 'Version/', 'Viber/', 'ChatGPT-User', 'Perplexity-User', 'Claude-User', 'MistralAI-User',
        'Googlebot', 'bingbot', 'Baiduspider', 'YandexBot', 'DuckDuckBot', 'SeznamBot', 'PetalBot',
        'facebookexternalhit', 'Facebot', 'Amazonbot', 'Twitterbot', 'Discordbot', 'Slackbot',
        'TelegramBot', 'SkypeUriPreview', 'TeamsBot', 'ClaudeBot', 'GPTBot', 'OAI-SearchBot',
        'PerplexityBot', 'Bytespider', 'TikTokBot', 'TikTokSpider', 'QwenBot', 'Meta-ExternalAgent', 'AhrefsBot',
        'SemrushBot', 'MJ12bot', 'DotBot', 'Cloudflare', 'CCBot', 'Applebot', 'YouBot',
        'Uptime-Kuma'
    ];
    
    foreach ($identified_patterns as $pattern) {
        if (stripos($user_agent, $pattern) !== false) {
            $already_identified = true;
            break;
        }
    }
    
    if (!$already_identified && $log_browser_user) {
        $country_allowed = true;
        
        if ($log_browser_limit_country) {
            $allowed_countries = get_option('bot_killer_allowed_countries', array());
            if (!empty($allowed_countries)) {
                $geo = $this->get_geo_location($ip);
                $country_code = is_array($geo) ? ($geo['country_code'] ?? null) : null;
                
                if ($country_code && !in_array($country_code, $allowed_countries)) {
                    $country_allowed = false;
                } elseif (!$country_code) {
                    $country_allowed = false;
                }
            }
        }
        
        if ($country_allowed) {
            $log_key = 'bot_killer_browser_logged_' . md5($ip);
            $ttl_seconds = $log_browser_ttl * HOUR_IN_SECONDS;
            
            if (!get_transient($log_key)) {
                $is_mobile = $this->is_mobile_browser($user_agent);
                $device_type = $is_mobile ? 'MOBILE' : 'DESKTOP UA';
                //$this->log_json($ip, "{$device_type} BROWSER - detected", 'user_detected', 'log-cart-user');
                $source = $this->get_referrer_source();
                $this->log_json($ip, "{$device_type} BROWSER - detected {$source}", 'user_detected', 'log-cart-user');
                set_transient($log_key, true, $ttl_seconds);
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
    '54.66.204.0/24',  '54.66.205.0/24', '54.66.206.0/24',  '54.66.207.0/24',
    '54.153.26.0/24',  '54.153.27.0/24', '54.153.28.0/24',  '54.153.29.0/24',
    '54.153.30.0/24',  '54.153.31.0/24', '54.206.48.0/24',  '54.206.49.0/24',
    '54.206.50.0/24',  '54.206.51.0/24', '54.252.170.0/24', '54.252.171.0/24',
    '54.252.172.0/24', '54.252.173.0/24'
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
    '185.70.184.0/22', '185.191.200.0/22', '185.209.28.0/22', '185.225.16.0/22',
    '193.108.72.0/22', '193.108.73.0/24',  '193.108.74.0/24', '193.108.75.0/24',
    '195.54.164.0/22', '2a0a:40c0::/32',   '2a0a:40c1::/32'
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
    '213.227.144.0/24', '213.227.145.0/24', '213.227.146.0/24', '213.227.147.0/24',
    '213.227.148.0/24', '213.227.149.0/24', '213.227.150.0/24', '213.227.151.0/24',
    '80.82.64.0/20',    '80.82.65.0/24',    '80.82.66.0/24',    '80.82.67.0/24',
    '80.82.68.0/24',    '80.82.69.0/24',    '80.82.70.0/24',    '80.82.71.0/24',
    '80.82.72.0/24',    '80.82.73.0/24',    '80.82.74.0/24',    '80.82.75.0/24',
    '80.82.76.0/24',    '80.82.77.0/24',    '80.82.78.0/24',    '80.82.79.0/24'
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
    '35.196.74.192/28', '35.196.115.160/27', '35.196.117.80/28', '35.196.118.144/28',
    '35.196.121.0/24',  '35.196.125.0/24',   '35.196.137.0/24',  '35.196.147.0/24',
    '35.203.210.0/23',  '35.203.212.0/23',   '35.203.214.0/23',  '35.227.70.0/23',
    '35.229.32.0/23',   '35.229.34.0/23',    '35.229.36.0/23',   '35.229.38.0/23',
    '35.229.40.0/23',   '35.229.42.0/23',    '35.229.44.0/23',   '35.229.46.0/23',
    '35.229.48.0/23',   '35.229.50.0/23',    '35.229.52.0/23',   '35.229.54.0/23',
    '35.229.56.0/23',   '35.229.58.0/23',    '35.229.60.0/23',   '35.229.62.0/23',
    '35.236.0.0/17',    '35.242.0.0/15'
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
    
$ranges = [
    '23.111.64.0/18',   '45.114.16.0/20',   '45.114.16.0/22',   '45.114.20.0/22',
    '45.114.24.0/22',   '45.114.28.0/22',   '45.114.30.0/24',   '45.114.31.0/24',
    '45.114.32.0/22',   '45.114.36.0/22',   '45.114.40.0/22',   '45.114.44.0/22',
    '45.114.48.0/22',   '45.114.52.0/22',   '45.114.56.0/22',   '45.114.60.0/22',
    '45.114.62.0/24',   '45.114.63.0/24',   '103.135.60.0/22',  '103.135.62.0/24',
    '103.135.63.0/24',  '149.129.128.0/17', '149.129.128.0/18', '149.129.192.0/18',
    '149.129.224.0/19', '149.129.240.0/20', '149.129.248.0/21', '149.129.252.0/22',
    '149.129.254.0/23', '149.129.255.0/24', '161.117.0.0/16',   '161.117.128.0/17',
    '161.117.192.0/18', '161.117.224.0/19', '161.117.240.0/20', '161.117.248.0/21',
    '161.117.252.0/22', '161.117.254.0/23', '161.117.255.0/24', '163.171.128.0/17',
    '170.179.128.0/17', '170.179.192.0/18', '170.179.224.0/19', '170.179.240.0/20',
    '170.179.248.0/21', '170.179.252.0/22', '170.179.254.0/23', '170.179.255.0/24',
    '182.16.128.0/17',  '182.16.192.0/18',  '182.16.224.0/19',  '182.16.240.0/20',
    '182.16.248.0/21',  '182.16.252.0/22',  '182.16.254.0/23',  '182.16.255.0/24',
    '202.89.96.0/19',   '202.89.112.0/20',  '202.89.120.0/21',  '202.89.124.0/22',
    '202.89.126.0/23',  '202.89.127.0/24',  '203.107.0.0/17',   '203.107.128.0/17',
    '203.107.160.0/19', '203.107.192.0/18', '203.107.224.0/19', '203.107.240.0/20',
    '203.107.248.0/21', '203.107.252.0/22', '203.107.254.0/23', '203.107.255.0/24',
    '208.127.64.0/18',  '208.127.96.0/19',  '208.127.112.0/20', '208.127.120.0/21',
    '208.127.124.0/22', '208.127.126.0/23', '208.127.127.0/24'
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
    '77.75.72.0/21',  '77.75.72.0/24',  '77.75.73.0/24',  '77.75.74.0/24',
    '77.75.75.0/24',  '77.75.76.0/24',  '77.75.77.0/24',  '77.75.78.0/24',
    '77.75.79.0/24',  '185.41.20.0/22', '185.41.20.0/24', '185.41.21.0/24',
    '185.41.22.0/24', '185.41.23.0/24', '193.85.16.0/20', '193.85.16.0/24',
    '193.85.17.0/24', '193.85.18.0/24', '193.85.19.0/24', '193.85.20.0/24',
    '193.85.21.0/24', '193.85.22.0/24', '193.85.23.0/24', '193.85.24.0/24',
    '193.85.25.0/24', '193.85.26.0/24', '193.85.27.0/24', '193.85.28.0/24',
    '193.85.29.0/24', '193.85.30.0/24', '193.85.31.0/24', '2a02:598::/32'
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
    '103.104.128.0/20', '103.104.128.0/24', '103.104.129.0/24', '103.104.130.0/24',
    '103.104.131.0/24', '103.104.132.0/24', '103.104.133.0/24', '103.104.134.0/24',
    '103.104.135.0/24', '103.104.136.0/24', '103.104.137.0/24', '103.104.138.0/24',
    '103.104.139.0/24', '103.104.140.0/24', '103.104.141.0/24', '103.104.142.0/24',
    '103.104.143.0/24', '119.8.0.0/15',     '119.8.0.0/16',     '119.9.0.0/16',
    '119.10.0.0/17',    '119.10.128.0/17',  '159.138.0.0/15',   '159.138.0.0/16',
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
    
    $this->log_json($ip, strtoupper($bot_name) . " bot detected - {$short_agent} ({$method_text})", 'bot_detected', 'log-cart-bot');
}

private function get_facebook_ip_ranges() {
    $cache_key = 'bot_killer_facebook_ips';
    $ranges = get_transient($cache_key);
    
    if ($ranges !== false) {
        return $ranges;
    }
    
    // Try to fetch fresh ranges
    $ranges = $this->fetch_facebook_ip_ranges('cron');
    
    // Fallback to static list if fetch failed
    if (empty($ranges)) {
        $ranges = [
            // IPv4 Facebook
            '31.13.24.0/21', '31.13.64.0/18', '45.64.40.0/22', '66.220.144.0/20',
            '69.63.176.0/20', '69.171.224.0/19', '74.119.76.0/22', '102.132.96.0/20',
            '103.4.96.0/22', '129.134.0.0/17', '147.75.208.0/20', '157.240.0.0/16',
            '163.114.128.0/20', '173.252.64.0/18', '179.60.192.0/22', '185.60.216.0/22',
            '185.89.216.0/22', '204.15.20.0/22',
            // IPv6 Facebook
            '2a03:2880::/32', '2a03:2800::/32', '2620:10d:c000::/44', '2620:10d:4000::/40',
            '2401:db00::/32', '2803:6080::/32', '2c0f:f248::/32',
        ];
    }
    
    set_transient($cache_key, $ranges, WEEK_IN_SECONDS);
    return $ranges;
}

private function fetch_facebook_ip_ranges($source = 'cron') {
    $ranges = [];
    $success = false;
    
    // Try to get from RADB for AS32934 (Facebook)
    $response = wp_remote_get('https://whois.radb.net/query?searchtext=AS32934&format=json', [
        'timeout' => 20,
        'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
    ]);
    
    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['objects']['route']) && is_array($data['objects']['route'])) {
            foreach ($data['objects']['route'] as $route) {
                if (isset($route['value'])) {
                    $ranges[] = $route['value'];
                }
            }
            $success = true;
        }
    }
    
    // Also try AS63293 (additional Facebook ASN)
    $response2 = wp_remote_get('https://whois.radb.net/query?searchtext=AS63293&format=json', [
        'timeout' => 15,
        'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
    ]);
    
    if (!is_wp_error($response2) && wp_remote_retrieve_response_code($response2) === 200) {
        $data2 = json_decode(wp_remote_retrieve_body($response2), true);
        if (isset($data2['objects']['route']) && is_array($data2['objects']['route'])) {
            foreach ($data2['objects']['route'] as $route) {
                if (isset($route['value'])) {
                    $ranges[] = $route['value'];
                }
            }
            $success = true;
        }
    }
    
    // Remove duplicates
    $ranges = array_values(array_unique($ranges));
    
    // Log result
    if ($success && !empty($ranges)) {
        if ($source === 'manual') {
            $this->log_json('SYSTEM', sprintf('Facebook IP ranges manually updated: %d ranges', count($ranges)), 'system', 'log-default');
        } else {
            $this->log_json('SYSTEM', sprintf('Facebook IP ranges updated via cron: %d ranges', count($ranges)), 'system', 'log-default');
        }
    } else {
        if ($source === 'manual') {
            $this->log_json('SYSTEM', 'Facebook IP ranges update failed - using fallback', 'system', 'log-default');
        } else {
            $this->log_json('SYSTEM', 'Facebook IP ranges update failed - using fallback', 'system', 'log-default');
        }
    }
    
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
        $this->log_json('SYSTEM', 'Starting manual update of all bot IP ranges...', 'system', 'log-default');
    } else {
        $this->log_json('SYSTEM', 'Starting weekly update of all bot IP ranges...', 'system', 'log-default');
    }
    
    // Cloudflare
    $this->update_cloudflare_ips($source);
    
    // Google & Bing
    $this->google_ips = $this->fetch_google_ips($source);
    $this->bing_ips = $this->fetch_bing_ips($source);
    set_transient('bot_killer_google_ips_v2', $this->google_ips, WEEK_IN_SECONDS);
    set_transient('bot_killer_bing_ips_v2', $this->bing_ips, WEEK_IN_SECONDS);
    
    // Update Facebook IP ranges
    delete_transient('bot_killer_facebook_ips');
    $this->fetch_facebook_ip_ranges($source);
    $this->get_facebook_ip_ranges(); // This will cache the result
    
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
        $this->log_json('SYSTEM', 'Manual update of all bot IP ranges completed.', 'system', 'log-default');
    } else {
        $this->log_json('SYSTEM', 'Weekly update of all bot IP ranges completed.', 'system', 'log-default');
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
    wp_cache_delete('bot_killer_blocklist', $this->cache_group);
}


public function update_tor_exit_nodes($source = 'cron') {
    $url = 'https://check.torproject.org/exit-addresses';
    $body = $this->safe_api_request($url, ['timeout' => 15], 'tor_nodes');
    
    if ($body === false) {
        $this->log_json('SYSTEM', 'Failed to update Tor exit nodes list', 'system', 'log-default');
        return false;
    }
    
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
        set_transient('bot_killer_tor_nodes', $tor_ips, 12 * HOUR_IN_SECONDS);
        
        if ($source === 'manual') {
            $this->log_json('SYSTEM', 'Tor exit nodes list manually updated. Total: ' . count($tor_ips) . ' IPs', 'system', 'log-default');
        } else {
            $this->log_json('SYSTEM', 'Tor exit nodes list updated via cron. Total: ' . count($tor_ips) . ' IPs', 'system', 'log-default');
        }
        
        return true;
    }
    
    $this->log_json('SYSTEM', 'Tor exit nodes list update failed - no IPs found', 'system', 'log-default');
    return false;
}

    private function is_tor_exit_node($ip) {
        $tor_ips = get_transient('bot_killer_tor_nodes');
        if (false === $tor_ips) {
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
    $primary_url = get_option('bot_killer_geoip_primary_url', 'http://ip-api.com/json/{ip}');
    $fallback_url = get_option('bot_killer_geoip_fallback_url', '');
    $geoip_fallback = get_option('bot_killer_geoip_fallback', 1);
    
    // Primary service
    $location = $this->geoip_lookup_by_url($ip, $primary_url);
    
    // Check if location is valid
    $is_valid_location = (
        $location !== null && 
        isset($location['country_code']) && 
        !empty($location['country_code'])
    );
    
    // Fallback if primary failed
    if (!$is_valid_location && $geoip_fallback && !empty($fallback_url)) {
        $location = $this->geoip_lookup_by_url($ip, $fallback_url);
    }
    
    if ($location !== null && isset($location['country_code']) && !empty($location['country_code'])) {
        $cache_hours = get_option('bot_killer_geoip_cache_hours', 24);
        set_transient($cache_key, $location, $cache_hours * HOUR_IN_SECONDS);
    } else {
        set_transient($cache_key, null, HOUR_IN_SECONDS);
    }
    
    return $location;
}

    
private function get_asn_for_ip($ip) {
    $cache_key = 'bot_killer_asn_' . md5($ip);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return $cached;
    }
    
    $location = $this->get_geo_location($ip);
    
    if ($location && isset($location['asn']) && !empty($location['asn'])) {
        $asn = [
            'asn' => $location['asn'],
            'as_name' => $location['as_name'] ?? 'unknown'
        ];
        set_transient($cache_key, $asn, DAY_IN_SECONDS);
        return $asn;
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
        
        // Skip IPv4 ranges for IPv6 IPs
        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        
        $subnet = @inet_pton($subnet);
        $ip_bin = @inet_pton($ip);
        
        if ($subnet === false || $ip_bin === false) return false;
        
        if ($mask <= 0) return true;
        if ($mask >= 128) return $ip_bin === $subnet;
        
        $bytes = floor($mask / 8);
        $bits = $mask % 8;
        
        for ($i = 0; $i < $bytes; $i++) {
            if ($ip_bin[$i] !== $subnet[$i]) return false;
        }
        
        if ($bits > 0) {
            $mask_byte = chr(0xFF << (8 - $bits));
            return (($ip_bin[$bytes] & $mask_byte) === ($subnet[$bytes] & $mask_byte));
        }
        
        return true;
    }
    return $ip === $range;
}

private function get_cached_blocklist() {
    $cached = wp_cache_get('bot_killer_blocklist', $this->cache_group);
    if (false === $cached) {
        $cached = [
            'blocked' => file_exists($this->block_file) ? 
                file($this->block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [],
            'whitelist' => file_exists($this->custom_white_file) ? 
                file($this->custom_white_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : []
        ];
        wp_cache_set('bot_killer_blocklist', $cached, $this->cache_group, 60);
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
    static $recursion_guard = false;
    
    if ($recursion_guard) {
        return false;
    }
    
    $recursion_guard = true;
    
    // Read file directly, no cache
    $custom_blocks = file_exists($this->custom_block_file) ? 
        file($this->custom_block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    
    $result = false;
    
    foreach ($custom_blocks as $block) {
        $block = trim($block);
        if (strpos($block, '#') === 0) continue;
        if (empty($block)) continue;
        if ($this->ip_in_range($ip, $block)) {
            $result = true;
            break;
        }
    }
    
    $recursion_guard = false;
    
    return $result;
}

private function get_block_meta() {
    // Return cached version if available
    if ($this->block_meta_cache !== null) {
        return $this->block_meta_cache;
    }
    
    if (!file_exists($this->block_meta_file)) {
        $this->block_meta_cache = [];
        return [];
    }
    
    $content = file_get_contents($this->block_meta_file);
    if ($content === false) {
        $this->block_meta_cache = [];
        return [];
    }
    
    $data = json_decode($content, true);
    $this->block_meta_cache = is_array($data) ? $data : [];
    return $this->block_meta_cache;
}

private function save_block_meta($meta) {
    file_put_contents($this->block_meta_file, wp_json_encode($meta, JSON_PRETTY_PRINT), LOCK_EX);
    if (is_writable($this->block_meta_file)) {
        chmod($this->block_meta_file, 0644);
    }
    // Update cache
    $this->block_meta_cache = $meta;
}

// Method add_block_meta() - STORE GEOIP
private function add_block_meta($ip, $reason, $bot_name = null, $verification_method = null, $block_source = null) {
    $meta = $this->get_block_meta();
    $block_time = time();
    $unblock_hours = get_option('bot_killer_unblock_hours', 24);
    $unblock_time = $block_time + ($unblock_hours * 3600);
    
    $geo = $this->get_geo_location($ip);
    
    $asn_info = $this->get_asn_for_ip($ip);
    $asn = $asn_info ? $asn_info['asn'] : null;
    $as_name = $asn_info ? $asn_info['as_name'] : null;
    
    $meta[$ip] = [
        'blocked_at' => $block_time,
        'blocked_at_readable' => $this->get_current_time(),
        'unblock_at' => $unblock_time,
        'unblock_at_readable' => date('Y-m-d H:i:s', $unblock_time),
        'reason' => $reason,
        'geo' => $geo,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        'asn' => $asn,
        'as_name' => $as_name,
        'bot_name' => $bot_name,
        'verification_method' => $verification_method,
        'block_source' => $block_source
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
            $this->log_file => "",
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
                        $this->log_json($ip, 'AUTO-UNBLOCKED during access check (expired)', 'admin_action', 'log-admin-action');
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

private function block_ip($ip, $reason, $bot_name = null, $verification_method = null, $block_source = null) {
    if ($this->is_ip_in_custom_blocklist($ip)) {
        return;
    }
    
    // ========== ATOMIC LOCK USING add_option ==========
    $lock_key = 'bot_killer_lock_' . md5($ip);
    $now = time();
    $ttl = 5;
    
    if (add_option($lock_key, $now, '', 'no')) {
        // Lock obtained
    } else {
        $existing = (int) get_option($lock_key);
        if (($now - $existing) > $ttl) {
            // Lock expired - take it
            update_option($lock_key, $now, false);
        } else {
            // Lock is still active - another request is blocking this IP
            return;
        }
    }
    // ===================================================
    
    // Check whitelist
    if ($this->is_ip_in_custom_whitelist($ip)) {
        $this->log_json($ip, "whitelisted ip - not blocked", 'whitelist', 'log-whitelist');
        delete_option($lock_key);
        return;
    }

    // Direct file check (no cache) to avoid race condition
    $blocked_ips = file_exists($this->block_file) ? 
        file($this->block_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        
    if (in_array($ip, $blocked_ips)) {
        delete_option($lock_key);
        return;
    }

    // Write to block file
    file_put_contents($this->block_file, $ip . "\n", FILE_APPEND | LOCK_EX);
    if (is_writable($this->block_file)) {
        chmod($this->block_file, 0644);
    }

    $this->add_block_meta($ip, $reason, $bot_name, $verification_method, $block_source);
    wp_cache_delete('bot_killer_blocklist', $this->cache_group);
    
    $unblock_hours = get_option('bot_killer_unblock_hours', 24);
    $this->log_json($ip, "IP BLOCKED - {$reason} (" . sprintf('auto-unblock in %s hours', $unblock_hours) . ")", 'blocked', 'log-blocked');
    
    // Release lock
    delete_option($lock_key);
}

private function log_json($ip, $message, $type, $style) {
    $this->rotate_log_if_needed();
    $current_time = $this->get_current_time();
    
    if ($ip === 'SYSTEM') {
        $full_message = "[{$current_time}] {$message}";
        $log_entry = [
            'time' => $current_time,
            'ip' => 'SYSTEM',
            'message' => $full_message,
            'type' => $type,
            'style' => $style,
            'user_agent' => null
        ];
        file_put_contents($this->log_file, json_encode($log_entry) . "\n", FILE_APPEND | LOCK_EX);
        return;
    }
    
    $geo = null;
    $location = '';
    $cloudflare = false;
    
    if ($this->is_ip_blocked($ip) || $this->is_ip_in_custom_blocklist($ip)) {
        $meta = $this->get_block_meta();
        if (isset($meta[$ip]) && isset($meta[$ip]['geo'])) {
            $geo = $meta[$ip]['geo'];
        } else {
            $location = $this->is_ip_in_custom_blocklist($ip) ? ' [custom blocklist]' : ' [auto-blocked]';
        }
    } else {
        $geo = $this->get_geo_location($ip);
    }
    
    if ($geo && is_array($geo) && isset($geo['country_code'])) {
        $city = !empty($geo['city']) ? $geo['city'] : 'unknown';
        $location = ' [' . $geo['country_code'] . ' - ' . $city . ']';
    }
    
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $proxy_ip = $_SERVER['REMOTE_ADDR'];
        if (!empty($this->cloudflare_ips)) {
            foreach ($this->cloudflare_ips['v4'] as $range) {
                if ($this->ip_in_range($proxy_ip, $range)) { 
                    $cloudflare = true; 
                    break; 
                }
            }
            if (!$cloudflare) {
                foreach ($this->cloudflare_ips['v6'] as $range) {
                    if ($this->ip_in_range($proxy_ip, $range)) { 
                        $cloudflare = true; 
                        break; 
                    }
                }
            }
        }
    }
    
    if ($cloudflare) {
        $location .= ' - Cloudflare';
    }
    
    $full_message = "[{$current_time}] ip: {$ip}{$location} | {$message}";
    
    $log_entry = [
        'time' => $current_time,
        'ip' => $ip,
        'message' => $full_message,
        'type' => $type,
        'style' => $style,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ];
    
    if ($geo && is_array($geo)) {
        $log_entry['country'] = $geo['country_code'] ?? null;
        $log_entry['city'] = $geo['city'] ?? null;
    }
    
    if ($cloudflare) {
        $log_entry['cloudflare'] = true;
    }
    
    if ($this->is_ip_in_custom_blocklist($ip)) {
        $log_entry['blocklist_type'] = 'custom';
    } elseif ($this->is_ip_blocked($ip)) {
        $log_entry['blocklist_type'] = 'auto';
    }
    
    file_put_contents($this->log_file, json_encode($log_entry) . "\n", FILE_APPEND | LOCK_EX);
}

public function check_if_blocked() {
    if (is_admin() || is_user_logged_in()) return;
    
    $ip = $this->get_ip();
    
    // Early check using blocking_key
    $blocking_key = 'bot_killer_blocking_' . md5($ip);
    if (get_transient($blocking_key)) {
        wp_die(__('Access Denied - Your IP has been blocked due to suspicious activity.', 'bot-killer'), __('Blocked', 'bot-killer'), array('response' => 403));
    }
    
    if ($this->is_ip_blocked($ip)) {
        //$this->log_json($ip, "access attempt blocked", 'blocked', 'log-blocked');
        $this->log_json($ip, "BLOCKED - IP in auto-blocked list", 'blocked', 'log-blocked');
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

private function is_headless_browser($user_agent) {
    // Don't block Google PageRenderer
    if (strpos($user_agent, 'Google-PageRenderer') !== false) {
        return false;
    }
    
    // Headless detection for ALL browsers (including mobile)
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
    
    $this->maybe_start_session();
    
    if (isset($_SESSION['bot_killer_js_checked'])) {
        return;
    }
    
    $_SESSION['bot_killer_js_checked'] = true;
    
    $ip = $this->get_ip();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $cart_bot = $this->get_cart_interacting_bot($ip, $user_agent);
    if ($cart_bot) {
        return;
    }
    
    // Set JS cookie via JavaScript
    ?>
    <script type="text/javascript">
    (function() {
        // Set JS cookie
        document.cookie = "bot_killer_js=1; path=/; max-age=3600";
        
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
    
    // JS cookie is already set by JavaScript, just confirm
    wp_send_json_success('js_detected');
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
    "custom rule (%s): %d %s in %ds (actual: %d in %s)",
    $type_text,
    $custom_attempts,
    ($custom_type == 0 ? 'same products' : 'different products'),
    $custom_seconds,
    $actual_count,
    $time_str
) . " - " . $product_info, null, 'custom_rule_match', 'custom_rule');
                    
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

private function get_custom_blocklist_once() {
    // Force load custom blocklist into cache
    $this->get_cached_blocklist();
}

public function track_and_block($passed, $product_id, $quantity) {
    
    $ip = $this->get_ip();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $is_mobile = $this->is_mobile_browser($user_agent);
       
    // ========== FORCE LOAD CUSTOM BLOCKLIST ==========
    $this->get_custom_blocklist_once();
    // =================================================
    
    // ========== EARLY BLOCK CHECK ==========
    $blocking_key = 'bot_killer_blocking_' . md5($ip);
    if (get_transient($blocking_key)) {
        wc_add_notice(__('Access Denied - IP Blocked'), 'error');
        return false;
    }
    // =======================================
    
    // Allow logged-in users
    if (is_user_logged_in()) {
        $product = wc_get_product($product_id);
        if (!$product) {
            wc_add_notice(__('Invalid product', 'bot-killer'), 'error');
            return false;
        }
        
        $product_price = $product->get_price();
        
        $user = wp_get_current_user();
        $is_admin = in_array('administrator', $user->roles) || in_array('shop_manager', $user->roles);
        $admin_suffix = $is_admin ? ' [ADMIN]' : '';
        $mobile_suffix = $is_mobile ? ' [MOBILE]' : '';
        
        $this->log_json($ip, sprintf("ADD TO CART - Product ID: %d, Qty: %d, Price: %s%s%s", $product_id, $quantity, $product_price, $admin_suffix, $mobile_suffix), 'add_to_cart', 'log-add-to-cart');
        
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
        $this->log_json($ip, "whitelisted ip - activity allowed", 'whitelist', 'log-whitelist');
        return $passed;
    }

    // =============================================
    // PRIORITY 2: CUSTOM BLOCKLIST
    // =============================================
    if ($this->is_ip_in_custom_blocklist($ip)) {
        
        $this->block_ip($ip, 'CUSTOM BLOCKLIST', null, 'custom_blocklist', 'custom');
    
        wc_add_notice(__('Access Denied - IP Blocked'), 'error');
    
        $this->log_json($ip, "BLOCKED - IP in custom list", 'blocked', 'log-blocked');
    
        return false;
    }    

    // =============================================
    // PRIORITY 3: AUTO-BLOCKED
    // =============================================
    if ($this->is_ip_blocked($ip)) {
        wc_add_notice(__('Access Denied - IP Blocked'), 'error');
        return false;
    }
    
    // =============================================
    // PRIORITY 3a: UA ROTATION DETECTION
    // =============================================
    if ($this->check_ua_rotation($ip, $user_agent)) {
        $this->block_ip($ip, "UA rotation detected - bot behavior", null, 'ua_rotation_detected', 'ua_rotation');
        wc_add_notice(__('Access Denied - Suspicious activity detected'), 'error');
        return false;
    }
    
    // =============================================
    // PRIORITY 4: VERIFIED BOTS - Bypass ALL other checks
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
            // Yandex check
            if (strpos($hostname, '.yandex.ru') !== false || strpos($hostname, '.yandex.net') !== false) {
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
            
            // Bot ASN mappings (Bing removed - handled by DNS only in get_cart_interacting_bot)
            $bot_asns = [
                '15169' => 'google',      // Google
                '32934' => 'facebook',    // Facebook
                '63293' => 'facebook',    // Facebook
                '209242' => 'ahrefs',     // Ahrefs
                '203726' => 'semrush',    // Semrush
                '204734' => 'mj12bot',    // Majestic
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
        
        // Block social/hybrid bots on cart actions
        $social_hybrid_bots = ['facebook', 'whatsapp', 'linkedin', 'pinterest', 'twitter', 
                               'discord', 'slack', 'telegram', 'microsoftpreview', 'tiktok', 
                               'openai', 'anthropic', 'perplexity', 'gemini', 'google_ai', 
                               'bytespider', 'mistral', 'grok', 'deepseek', 'qwen', 'metaai'];
        
        if (in_array($cart_bot, $social_hybrid_bots)) {
            $this->log_json($ip, "BOT ABUSE: " . strtoupper($cart_bot) . " spoof - cart manipulation detected", 'spoof', 'log-spoof-attempt');
            $this->block_ip($ip, "BOT ABUSE: " . strtoupper($cart_bot) . " cart manipulation", $cart_bot, 'bot_abuse_detected', 'spoof_bot');
            wc_add_notice(__('Access Denied - Suspicious activity detected'), 'error');
            return false;
        }
        
        return $passed; // Strict bots (Google, Bing, etc.) bypass everything below
    }
    
    // ========== SPECIAL CHECK FOR WEBVIEW ==========
    $is_webview = (stripos($user_agent, 'wv') !== false || stripos($user_agent, 'WebView') !== false);
    
    if ($is_webview) {
        // Rate limiting for WebView cart actions
        $webview_key = 'bot_killer_webview_cart_' . md5($ip);
        $webview_count = get_transient($webview_key);
        
        if ($webview_count === false) {
            set_transient($webview_key, 1, 60);
        } else {
            $webview_count++;
            set_transient($webview_key, $webview_count, 60);
            
            if ($webview_count > 5) {
                $this->log_json($ip, "WebView bot detected - too many cart actions ({$webview_count} in 60s)", 'spoof', 'log-spoof-attempt');
                $this->block_ip($ip, "WebView bot - excessive cart actions", 'webview_bot', 'rate_limit', 'log-spoof-attempt');
                wc_add_notice(__('Access Denied - Suspicious activity detected'), 'error');
                return false;
            }
        }
        
        // Check JS/cookies after multiple requests
        $webview_suspicious_key = 'bot_killer_webview_suspicious_' . md5($ip);
        $is_suspicious = get_transient($webview_suspicious_key);
        $has_js = get_transient('bot_killer_js_detected_' . md5($ip));
        $has_cookies = !empty($_COOKIE);
        
        if ($is_suspicious) {
            if (!$has_js && !$has_cookies) {
                $this->log_json($ip, "WebView bot detected - no JS/cookies after multiple cart actions", 'spoof', 'log-spoof-attempt');
                $this->block_ip($ip, "WebView bot - no verification", 'webview_bot', 'no_js_cookies', 'log-spoof-attempt');
                wc_add_notice(__('Access Denied - Suspicious activity detected'), 'error');
                return false;
            } else {
                delete_transient($webview_suspicious_key);
                $this->log_json($ip, "WebView - verified as real user (JS/cookies found)", 'user_detected', 'log-cart-bot');
            }
        } else {
            if (!$has_js && !$has_cookies) {
                set_transient($webview_suspicious_key, true, 30);
                $this->log_json($ip, "WebView - suspicious (waiting for verification on cart action)", 'suspicious', 'log-cart-bot');
            }
        }
    }
    
    // ========== ALLOWED MULTI-BOT ON MOBILE - SKIP BROWSER INTEGRITY ==========
    $is_allowed_multi = $this->is_allowed_multi_bot($user_agent);
    $is_mobile = $this->is_mobile_browser($user_agent);
    
    $skip_browser_integrity = ($is_allowed_multi && $is_mobile);
    
    // =============================================
    // PRIORITY 5: TOR EXIT NODES
    // =============================================
    if (get_option('bot_killer_block_tor', 1)) {
        if ($this->is_tor_exit_node($ip)) {
            $this->log_json($ip, "Tor exit node detected and blocked", 'blocked', 'log-blocked');
            wc_add_notice(__('Access Denied - Anonymizer detected'), 'error');
            return false;
        }
    }
    
    // =============================================
    // PRIORITY 6: ASN BLOCK
    // =============================================
    $blocked_asns = get_option('bot_killer_blocked_asns', []);
    if (!empty($blocked_asns)) {
        $asn_info = $this->get_asn_for_ip($ip);
        if ($asn_info && isset($asn_info['asn']) && in_array($asn_info['asn'], $blocked_asns)) {
            $this->log_json($ip, "ASN {$asn_info['asn']} ({$asn_info['as_name']}) blocked", 'asn_blocked', 'log-asn-blocked');
            wc_add_notice(__('Access Denied - Network blocked'), 'error');
            return false;
        }
    }
    
    // =============================================
    // PRIORITY 7: HEADLESS DETECTION
    // =============================================
    if (get_option('bot_killer_block_headless', 1)) {
        if ($this->is_headless_browser($user_agent)) {
            $this->log_json($ip, "Headless browser detected - " . $user_agent, 'headless', 'log-headless');
            wc_add_notice(__('Access Denied - Automated browser detected'), 'error');
            return false;
        }
    }
       
    if (!$skip_browser_integrity) {
        $integrity_result = $this->check_browser_integrity_with_score($ip, $user_agent);
        if ($integrity_result === 'block') {
            $this->block_ip($ip, "Browser integrity check failed", null, 'browser_integrity_failed', 'browser_integrity');
            wc_add_notice(__('Access Denied - Browser integrity check failed', 'bot-killer'), 'error');
            return false;
        } elseif ($integrity_result === 'reject') {
            wc_add_notice(__('Your browser is not fully supported. Please enable JavaScript and cookies.', 'bot-killer'), 'error');
            return false;
        }
    }
    
    // =============================================
    // PRIORITY 9: COUNTRY FILTER
    // =============================================
    if ($this->is_ip_blocked($ip)) {
        return false;
    }

    $allowed_countries = get_option('bot_killer_allowed_countries', array());
    $block_unknown = get_option('bot_killer_block_unknown_country', 0);
    
    if (!empty($allowed_countries) && !$this->is_ip_in_custom_blocklist($ip)) {
        $geo = $this->get_geo_location($ip);
        $country_code = is_array($geo) ? ($geo['country_code'] ?? null) : null;
        
        if ($country_code && $country_code !== 'private') {
            if (!in_array($country_code, $allowed_countries)) {
                $this->log_json($ip, sprintf("REJECTED - country %s not allowed", $country_code), 'rejected', 'log-rejected');
                wc_add_notice(__('Purchases are only available from selected countries'), 'error');
                return false;
            }
        } else {
            if ($block_unknown) {
                $this->log_json($ip, "BLOCKED - unknown country", 'blocked', 'log-blocked');
                wc_add_notice(__('Unable to verify your location'), 'error');
                return false;
            }
        }
    }
    
    // =============================================
    // PRIORITY 10: OUT OF STOCK
    // =============================================
    $block_out_of_stock = get_option('bot_killer_block_out_of_stock', 1);
    if ($block_out_of_stock && !$product->is_in_stock()) {
        $this->block_ip($ip, __("Attempt to add out-of-stock product"), null, 'out_of_stock_attempt', 'out_of_stock');
        wc_add_notice(__('This product is out of stock'), 'error');
        return false;
    }
    
    // =============================================
    // PRIORITY 11: CUSTOM RULES
    // =============================================
    $transient_key = 'bot_killer_' . md5($product_id . '_' . $ip);
    $session_data = get_transient($transient_key);
    
    $custom_rules_enabled = get_option('bot_killer_custom_rules_enabled', 0);
    $custom_rules = get_option('bot_killer_custom_rules', '');
    
    $max_expiration = 3600;
    
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
    $mobile_suffix = $is_mobile ? ' [MOBILE]' : '';
    
    //$this->log_json($ip, sprintf("ADD TO CART - Product ID: %d, Qty: %d, Price: %s%s%s%s", $product_id, $quantity, $product_price, $admin_suffix, $cloudflare_suffix, $mobile_suffix), 'add_to_cart', 'log-add-to-cart');
    $source = $this->get_referrer_source();
$this->log_json($ip, sprintf("ADD TO CART - Product ID: %d, Qty: %d, Price: %s%s%s%s %s", $product_id, $quantity, $product_price, $admin_suffix, $cloudflare_suffix, $mobile_suffix, $source), 'add_to_cart', 'log-add-to-cart');
    
    return $passed;
}

public function track_order($order_id) {
    $logged = get_post_meta($order_id, '_bot_killer_logged', true);
    if ($logged) {
        return;
    }
    
    $order = wc_get_order($order_id);
    if (!$order) return;
    
    $order_date = $order->get_date_created();
    $order_timestamp = $order_date ? $order_date->getTimestamp() : 0;
    $current_time = time();
    $max_age = 24 * HOUR_IN_SECONDS;
    
    if ($current_time - $order_timestamp > $max_age) {
        update_post_meta($order_id, '_bot_killer_logged', true);
        return;
    }
    
    $ip = $this->get_ip();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $is_mobile = $this->is_mobile_browser($user_agent, $ip);
    $mobile_suffix = $is_mobile ? ' [MOBILE]' : '';
    
    // Check if admin
    $is_admin = false;
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        if (in_array('administrator', $user->roles) || in_array('shop_manager', $user->roles)) {
            $is_admin = true;
        }
    }
    $admin_suffix = $is_admin ? ' [ADMIN]' : '';
    
    // Detect if request came through Cloudflare
    $cloudflare = false;
    if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $proxy_ip = $_SERVER['REMOTE_ADDR'];
        if (!empty($this->cloudflare_ips)) {
            foreach ($this->cloudflare_ips['v4'] as $range) {
                if ($this->ip_in_range($proxy_ip, $range)) { 
                    $cloudflare = true; 
                    break; 
                }
            }
            if (!$cloudflare) {
                foreach ($this->cloudflare_ips['v6'] as $range) {
                    if ($this->ip_in_range($proxy_ip, $range)) { 
                        $cloudflare = true; 
                        break; 
                    }
                }
            }
        }
    }
    
    $total = $order->get_total();
    $item_count = $order->get_item_count();
    
    //$message = sprintf("PURCHASE - Order #%d: %d items, Total: %s%s%s", $order_id, $item_count, $total, $mobile_suffix, $admin_suffix);
    $source = $this->get_referrer_source();
    $message = sprintf("PURCHASE - Order #%d: %d items, Total: %s%s%s %s", $order_id, $item_count, $total, $mobile_suffix, $admin_suffix, $source);
    if ($cloudflare) {
        $message .= " - Cloudflare";
    }
    
    $this->log_json($ip, $message, 'purchase', 'log-purchase');
    
    update_post_meta($order_id, '_bot_killer_logged', true);
}

public function track_remove_from_cart($cart_item_key, $cart) {
    // Allow AJAX requests (cart updates via frontend)
    if ((is_admin() && !defined('DOING_AJAX')) || !function_exists('wc_get_product')) {
        return;
    }
    
    $ip = $this->get_ip();
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // ========== CHECK CUSTOM BLOCKLIST ==========
    if ($this->is_ip_in_custom_blocklist($ip)) {
        $this->log_json($ip, "BLOCKED - IP in custom list", 'blocked', 'log-blocked');
        return;
    }
    
    // ========== CHECK AUTO-BLOCKED ==========
    if ($this->is_ip_blocked($ip)) {
        $this->log_json($ip, "BLOCKED - IP in auto-blocked list", 'blocked', 'log-blocked');
        return;
    }

    
    $cart_item = $cart->removed_cart_contents[$cart_item_key] ?? null;
    if (!$cart_item) {
        return;
    }
    
    $product_id = $cart_item['product_id'];
    $quantity = $cart_item['quantity'];
    
    // ========== CHECK REMOVE CUSTOM RULES ==========
    if (!$this->check_remove_custom_rules($ip, $product_id)) {
        return;
    }
    
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
    $is_mobile = $this->is_mobile_browser($user_agent);
    $mobile_suffix = $is_mobile ? ' [MOBILE]' : '';
    
    $this->log_json($ip, sprintf("REMOVE FROM CART - Product ID: %d, Qty: %d, Price: %s%s%s", $product_id, $quantity, $product_price, $admin_suffix, $mobile_suffix), 'remove_cart', 'log-remove-cart');
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
                $this->log_json($ip, "AUTO-UNBLOCKED after {$unblock_hours} hours.", 'admin_action', 'log-admin-action');
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
        wp_cache_delete('bot_killer_blocklist', $this->cache_group);
    }
    
    // ========== CLEANUP EXPIRED LOCKS ==========
    global $wpdb;
    $expired_lock_time = time() - 3600; // Older than 1 hour
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE 'bot_killer_lock_%' 
             AND option_value < %d",
            $expired_lock_time
        )
    );
    // ===========================================
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

        'bot_killer_block_out_of_stock' => ['sanitize_callback' => 'intval'],
        'bot_killer_block_browser_integrity' => ['sanitize_callback' => 'intval'],
        'bot_killer_block_headless' => ['sanitize_callback' => 'intval'],
        'bot_killer_block_tor' => ['sanitize_callback' => 'intval'],
        'bot_killer_blocked_asns' => ['sanitize_callback' => [$this, 'sanitize_asn_list']],
        'bot_killer_ua_rotation_enabled' => ['sanitize_callback' => 'intval'],
        'bot_killer_ua_rotation_limit' => ['sanitize_callback' => 'intval'],
        'bot_killer_ua_rotation_window' => ['sanitize_callback' => 'intval'],
        'bot_killer_debug_mode' => ['sanitize_callback' => 'intval'],
        'bot_killer_auto_clean_blocklist' => ['sanitize_callback' => 'intval'],
        'bot_killer_remove_rules_enabled' => ['sanitize_callback' => 'intval'],
        'bot_killer_remove_rules' => ['sanitize_callback' => [$this, 'sanitize_remove_rules']],
        'bot_killer_log_app_user' => ['sanitize_callback' => 'intval'],
        'bot_killer_log_ai_user' => ['sanitize_callback' => 'intval'],
        'bot_killer_log_browser_user' => ['sanitize_callback' => 'intval'],
        'bot_killer_log_browser_limit_country' => ['sanitize_callback' => 'intval'],
        'bot_killer_log_browser_ttl' => ['sanitize_callback' => 'intval'],
        // GeoIP custom URLs
        'bot_killer_geoip_primary_url' => ['sanitize_callback' => 'sanitize_text_field'],
        'bot_killer_geoip_fallback_url' => ['sanitize_callback' => 'sanitize_text_field'],
    ];
    
    foreach ($settings as $setting => $args) {
        register_setting('bot_killer_settings', $setting, $args);
    }
}

public function sanitize_remove_rules($value) {
    if (empty($value)) {
        return "# 5 remove actions in 5 seconds\n5,5,2";
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
                $seconds >= 3 && $seconds <= 86400 &&
                in_array($type, [0, 1, 2])) {
                
                $clean_lines[] = $attempts . ',' . $seconds . ',' . $type;
            }
        }
    }
    
    return implode("\n", $clean_lines);
}

public function sanitize_custom_rules($value) {
    if (empty($value)) {
        // Return default rules if empty
        return "# 2 same products in 5 seconds\n2,5,0\n# 3 same products in 5 minutes\n3,300,0\n# 2 different products in 5 seconds\n2,5,1";
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
        __('Dashboard', 'bot-killer'),
        __('Dashboard', 'bot-killer'),
        'manage_options', 
        'bot-killer',
        array($this, 'admin_page')
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

// Handle Remove Rules save
if (isset($_POST['save_remove_rules']) && check_admin_referer('bot_killer_remove_rules')) {
    if (isset($_POST['bot_killer_remove_rules_enabled'])) {
        update_option('bot_killer_remove_rules_enabled', intval($_POST['bot_killer_remove_rules_enabled']));
    } else {
        update_option('bot_killer_remove_rules_enabled', 0);
    }
    
    if (isset($_POST['bot_killer_remove_rules'])) {
        $rules = wp_unslash($_POST['bot_killer_remove_rules']);
        $rules = sanitize_textarea_field($rules);
        update_option('bot_killer_remove_rules', $rules);
    }
    
    echo '<div class="notice notice-success is-dismissible"><p>' . 
         __('Remove rules saved successfully!', 'bot-killer') . 
         '</p></div>';
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
            
            wp_cache_delete('bot_killer_blocklist', $this->cache_group);
            
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
            
            wp_cache_delete('bot_killer_blocklist', $this->cache_group);
            
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
        
        wp_cache_delete('bot_killer_blocklist', $this->cache_group);
        
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
        
        // Get block meta before removing
        $block_meta = $this->get_block_meta();
        $blocked_time = isset($block_meta[$ip_to_unblock]['blocked_at_readable']) ? $block_meta[$ip_to_unblock]['blocked_at_readable'] : 'unknown';
        
        $this->remove_block_meta($ip_to_unblock);
        $this->log_json($ip_to_unblock, "manually unblocked by admin.", 'admin_action', 'log-admin-action');
        wp_cache_delete('bot_killer_blocklist', $this->cache_group);
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
        $this->log_json("ALL", "all auto-blocked ips manually cleared by admin", 'admin_action', 'log-admin-action');
        wp_cache_delete('bot_killer_blocklist', $this->cache_group);
        echo '<div class="notice notice-success"><p>' . __('all auto-blocked ips unblocked!', 'bot-killer') . '</p></div>';
    }
    
    if (isset($_POST['clear_log']) && check_admin_referer('clear_log')) {
        $clear_time = $this->get_current_time();
        file_put_contents($this->log_file, "", LOCK_EX);
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
    
    $block_ips = file_exists($this->custom_block_file) ? file_get_contents($this->custom_block_file) : '';
    $white_ips = file_exists($this->custom_white_file) ? file_get_contents($this->custom_white_file) : '';
    $allowed_countries = get_option('bot_killer_allowed_countries', array());
    
    // ========== READ LOG WITH LIMIT ==========
    $log_limit = isset($_GET['log_limit']) ? intval($_GET['log_limit']) : 300;
    $valid_limits = [300, 600, 1000, 1500, 2000];
    if (!in_array($log_limit, $valid_limits)) {
        $log_limit = 300;
    }
    
    $log_entries = [];
    if (file_exists($this->log_file)) {
        $log_content = file_get_contents($this->log_file);
        $lines = explode("\n", trim($log_content));
        foreach ($lines as $line) {
            if (empty($line)) continue;
            $entry = json_decode($line, true);
            if ($entry && isset($entry['time'])) {
                $log_entries[] = $entry;
            }
        }
    }
    $log_entries = array_reverse($log_entries);
    $log_entries = array_slice($log_entries, 0, $log_limit);
    $display_count = count($log_entries);
    // =========================================
    
    include BOTKILLER_PLUGIN_DIR . 'includes/views/admin-dashboard.php';
}

    public function maybe_cleanup_expired_blocks() {
        $last_cleanup = get_option('bot_killer_last_cleanup', 0);
        if (time() - $last_cleanup > 3600) {
            $this->cleanup_expired_blocks();
            update_option('bot_killer_last_cleanup', time());
        }
    }
    
/**
 * Check if IP is rotating User-Agent too frequently
 * Supports both IPv4 and IPv6
 * @param string $ip
 * @param string $user_agent
 * @return bool - true if suspicious (too many rotations)
 */
private function check_ua_rotation($ip, $user_agent) {
    $enabled = get_option('bot_killer_ua_rotation_enabled', 0);
    
    if (!$enabled) {
        return false;
    }
    
    if ($this->is_ip_blocked($ip)) {
        return true;
    }
    
    $limit = get_option('bot_killer_ua_rotation_limit', 2);
    $window = get_option('bot_killer_ua_rotation_window', 5);
    
    $key = 'bot_killer_ua_history_' . md5($ip);
    $history = get_transient($key);
    $current_time = time();
    
    if ($history === false) {
        $history = [];
    }
    
    $history = array_filter($history, function($time) use ($current_time, $window) {
        return ($current_time - $time) < $window;
    });
    
    $ua_hash = md5($user_agent);
    $history[$ua_hash] = $current_time;
    $unique_ua_count = count($history);
    
    set_transient($key, $history, $window + 60);
    
    if ($unique_ua_count > $limit) {
        $this->log_json($ip, "UA rotation detected - {$unique_ua_count} different UAs in {$window} seconds", 'spoof', 'log-spoof-attempt');
        return true;
    }
    
    return false;
}

/**
 * Check if User-Agent is a mobile browser
 * @param string $user_agent
 * @param string $ip
 * @return bool
 */
private function is_mobile_browser($user_agent, $ip = null) {
    if ($ip === null) {
        $ip = $this->get_ip();
    }
    
    $mobile_agents = [
        'Android', 'webOS', 'iPhone', 'iPad', 'iPod', 'BlackBerry', 
        'Windows Phone', 'Opera Mini', 'Mobile', 'mobile'
    ];
    
    $result = false;
    
    foreach ($mobile_agents as $agent) {
        if (stripos($user_agent, $agent) !== false) {
            $result = true;
            break;
        }
    }
    
    return $result;
}

/**
 * Get or create browser session ID
 * @return string
 */
private function get_browser_session_id() {
    $session_id = isset($_COOKIE['bot_killer_session']) ? $_COOKIE['bot_killer_session'] : '';
    
    if (empty($session_id)) {
        $session_id = bin2hex(random_bytes(16));
        setcookie('bot_killer_session', $session_id, time() + 86400, '/', '', false, true);
    }
    
    return $session_id;
}

/**
 * Browser integrity check with scoring system - session based with challenge
 * @param string $ip
 * @param string $user_agent
 * @return bool - true if passes, false if should block
 */
private function check_browser_integrity_with_score($ip, $user_agent) {
    $is_mobile = $this->is_mobile_browser($user_agent);
    $session_id = $this->get_browser_session_id();
    
    $session_key = 'bot_killer_browser_session_' . md5($session_id);
    $session = get_transient($session_key);
    
    if ($session === false) {
        setcookie('bot_killer_verify', '1', time() + 7200, '/', '', false, true);
        
        if (!$is_mobile) {
            $missing_cookies_score = 0;
            
            if (empty($_COOKIE)) {
                $missing_cookies_score += 3;
            }
            
            if (!isset($_SERVER['HTTP_REFERER']) || empty($_SERVER['HTTP_REFERER'])) {
                $missing_cookies_score += 1;
            }
            
            if ($missing_cookies_score >= 3) {
                $this->log_json($ip, "REJECTED - Bot detected on first request - no cookies, score: {$missing_cookies_score}", 'rejected', 'log-rejected');
                wc_add_notice(__('Your browser is not fully supported. Please enable JavaScript and cookies.', 'bot-killer'), 'error');
                return 'reject';
            }
            
        }
        
        set_transient($session_key, [
            'stage' => 1,
            'attempts' => 1,
            'score' => 0,
            'time' => time()
        ], 86400);

        return true;
    }
    
    $attempts = isset($session['attempts']) ? $session['attempts'] + 1 : 2;
    $session['attempts'] = $attempts;
    
    $score = isset($session['score']) ? $session['score'] : 0;
    
    $has_js_cookie = isset($_COOKIE['bot_killer_js']) && $_COOKIE['bot_killer_js'] === '1';
    if (!$has_js_cookie) {
        $score += 3;
        if ($attempts > 3) {
            $score += ($attempts - 3);
        }
    }
    
    $has_verify_cookie = isset($_COOKIE['bot_killer_verify']) && $_COOKIE['bot_killer_verify'] === '1';
    if (!$has_verify_cookie) {
        $score += 1;
    }
    
    if (!isset($_SERVER['HTTP_REFERER']) || empty($_SERVER['HTTP_REFERER'])) {
        $score += 1;
    }
    
    $session['score'] = $score;
    set_transient($session_key, $session, 86400);
    
    // Thresholds
    //$pass_threshold = $is_mobile ? 4 : 2;
    //$reject_threshold = $is_mobile ? 9 : 4;
    //$block_threshold = $is_mobile ? 10 : 5;
    // New
    $pass_threshold = $is_mobile ? 5 : 2;
    $reject_threshold = $is_mobile ? 12 : 4;
    $block_threshold = $is_mobile ? 14 : 5;
    
    if ($score <= $pass_threshold) {
        return true;
    } elseif ($score <= $reject_threshold) {
        // REJECT - log only, no block
        $this->log_json($ip, "Browser integrity check failed - score: {$score} (rejected, not blocked)", 'rejected', 'log-rejected');
        return 'reject';
    } else {
        // BLOCK - score >= block_threshold
        $this->log_json($ip, "Browser integrity check failed - score: {$score}", 'browser_failed', 'log-browser-failed');
        return 'block';
    }
}


/**
 * Start session safely if not already started
 * Only starts session when headers haven't been sent
 */
private function maybe_start_session() {
    if (headers_sent()) {
        return false;
    }
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    return true;
}

/**
 * Safe API request with error handling
 * @param string $url
 * @param array $args
 * @param string $context
 * @return array|false Response body or false on error
 */
private function safe_api_request($url, $args = array(), $context = 'api') {
    $defaults = array(
        'timeout' => 20,
        'sslverify' => true,
        'user-agent' => 'Bot Killer WordPress Plugin/' . BOTKILLER_VERSION
    );
    
    $args = wp_parse_args($args, $defaults);
    
    $response = wp_remote_get($url, $args);
    
    // Check for WP_Error
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        $this->log_json('SYSTEM', "API error [{$context}]: {$error_message}", 'system', 'log-default');
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Bot Killer API error [{$context}]: {$error_message}");
        }
        
        return false;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    // Check HTTP status
    if ($status_code !== 200) {
        $this->log_json('SYSTEM', "API error [{$context}]: HTTP {$status_code}", 'system', 'log-default');
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Bot Killer API error [{$context}]: HTTP {$status_code} - " . substr($body, 0, 200));
        }
        
        return false;
    }
    
    // Check for empty response
    if (empty($body)) {
        $this->log_json('SYSTEM', "API error [{$context}]: Empty response", 'system', 'log-default');
        return false;
    }
    
    return $body;
}

private function check_remove_custom_rules($ip, $product_id) {
    $enabled = get_option('bot_killer_remove_rules_enabled', 0);
    if (!$enabled) {
        return true;
    }
    
    $rules_text = get_option('bot_killer_remove_rules', '');
    if (empty($rules_text)) {
        return true;
    }
    
    $rules = explode("\n", $rules_text);
    
    // Track count for this IP
    $count_key = 'bot_killer_remove_count_' . md5($ip);
    $count_data = get_transient($count_key);
    $current_time = time();
    
    // Track unique products
    $unique_key = 'bot_killer_remove_unique_' . md5($ip);
    $unique_data = get_transient($unique_key);
    
    if ($count_data === false) {
        $count_data = [
            'count' => 1,
            'first_time' => $current_time,
            'last_time' => $current_time,
            'products' => [$product_id => $current_time]
        ];
    } else {
        // Clean old products (older than 1 hour)
        if (isset($count_data['products']) && is_array($count_data['products'])) {
            $count_data['products'] = array_filter(
                $count_data['products'],
                function($time) use ($current_time) {
                    return ($current_time - $time) < 3600;
                }
            );
        } else {
            $count_data['products'] = [];
        }
        
        $count_data['products'][$product_id] = $current_time;
        $count_data['count']++;
        $count_data['last_time'] = $current_time;
    }
    
    $unique_count = count($count_data['products']);
    $time_span = $current_time - $count_data['first_time'];
    $minutes = floor($time_span / 60);
    $seconds = $time_span % 60;
    $time_str = $minutes > 0 ? "{$minutes}m {$seconds}s" : "{$seconds}s";
    
    set_transient($count_key, $count_data, 3600);
    set_transient($unique_key, $count_data, 3600);
    
    foreach ($rules as $rule) {
        $rule = trim($rule);
        if (empty($rule) || strpos($rule, '#') === 0) continue;
        
        $parts = explode(',', $rule);
        $parts = array_map('trim', $parts);
        
        if (count($parts) >= 2) {
            $attempts = intval($parts[0]);
            $seconds = intval($parts[1]);
            $type = isset($parts[2]) ? intval($parts[2]) : 2;
            
            $matched = false;
            $actual_count = 0;
            
            switch ($type) {
                case 0: // same product
                    $same_count = isset($count_data['products'][$product_id]) ? 
                        count(array_keys($count_data['products'], $product_id)) : 0;
                    if ($same_count >= $attempts) {
                        $matched = true;
                        $actual_count = $same_count;
                    }
                    break;
                case 1: // different products
                    if ($unique_count >= $attempts) {
                        $matched = true;
                        $actual_count = $unique_count;
                    }
                    break;
                case 2: // any removes
                    if ($count_data['count'] >= $attempts) {
                        $matched = true;
                        $actual_count = $count_data['count'];
                    }
                    break;
            }
            
            if ($matched && $time_span < $seconds) {
                $type_text = $type == 0 ? 'same product' : ($type == 1 ? 'different products' : 'any removes');
                $this->block_ip($ip, sprintf(
                    "remove rule (%s): %d removes in %ds (actual: %d in %s)",
                    $type_text,
                    $attempts,
                    $seconds,
                    $actual_count,
                    $time_str
                ), null, 'custom_rule_remove_match', 'custom_rule_remove');
                
                wc_add_notice(__('Too many cart operations. Please try again later.', 'bot-killer'), 'error');
                return false;
            }
        }
    }
    
    return true;
}

private function set_default_options() {
    if (get_option('bot_killer_remove_rules') === false) {
        $default_rules = "# 5 remove actions in 5 seconds\n5,5,2\n# 3 removes of same product in 10 seconds\n3,10,0\n# 7 removes of different products in 3 seconds\n7,3,1";
        update_option('bot_killer_remove_rules', $default_rules);
    }
    
    if (get_option('bot_killer_remove_rules_enabled') === false) {
        update_option('bot_killer_remove_rules_enabled', 0);
    }
}



private function geoip_lookup_by_url($ip, $url) {
    if (empty($url)) {
        return null;
    }
    
    // Replace {ip} placeholder with actual IP
    $request_url = str_replace('{ip}', urlencode($ip), $url);
    
    $response = wp_remote_get($request_url, [
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
    if (!is_array($data)) {
        return null;
    }
    
    // ========== EXTRACT COUNTRY CODE ==========
    $country_code = null;
    if (isset($data['country_code'])) {
        $country_code = $data['country_code'];
    } elseif (isset($data['countryCode'])) {
        $country_code = $data['countryCode'];
    } elseif (isset($data['country'])) {
        $country_code = $data['country'];
    }
    
    if (empty($country_code)) {
        return null;
    }
    
    // ========== EXTRACT CITY AND REGION ==========
    $city = null;
    $region = null;
    
    if (isset($data['city'])) {
        $city = $data['city'];
    } elseif (isset($data['cityName'])) {
        $city = $data['cityName'];
    }
    
    if (isset($data['region'])) {
        $region = $data['region'];
    } elseif (isset($data['regionName'])) {
        $region = $data['regionName'];
    }
    
    // ========== EXTRACT ASN (support nested paths) ==========
    $asn = null;
    $as_name = null;
    
    // Case 1: Direct asn field
    if (isset($data['asn'])) {
        $asn = str_replace('AS', '', $data['asn']);
        $as_name = $data['asnOrganization'] ?? $data['as_name'] ?? $data['asname'] ?? $data['org'] ?? null;
    }
    // Case 2: Nested in connection (ipwho.is format)
    elseif (isset($data['connection']['asn'])) {
        $asn = $data['connection']['asn'];
        $as_name = $data['connection']['org'] ?? $data['connection']['isp'] ?? null;
    }
    // Case 3: as field (ip-api.com format)
    elseif (isset($data['as'])) {
        $asn = str_replace('AS', '', $data['as']);
        $as_name = $data['asname'] ?? null;
    }
    // Case 4: Nested in asn object
    elseif (isset($data['asn']['asn'])) {
        $asn = str_replace('AS', '', $data['asn']['asn']);
        $as_name = $data['asn']['name'] ?? $data['asn']['organization'] ?? null;
    }
    // Case 5: org field with ASN prefix (ipinfo.io format)
    elseif (isset($data['org']) && preg_match('/AS(\d+)/', $data['org'], $matches)) {
        $asn = $matches[1];
        $as_name = preg_replace('/^AS\d+\s*/', '', $data['org']);
    }
    
    // ========== EXTRACT COUNTRY NAME ==========
    $country = $data['country_name'] ?? $data['countryName'] ?? $data['country'] ?? $country_code;
    
    return [
        'country' => $country,
        'country_code' => $country_code,
        'region' => $region ?? 'unknown',
        'city' => $city ?? 'unknown',
        'asn' => $asn,
        'as_name' => $as_name,
        'service' => parse_url($url, PHP_URL_HOST)
    ];
}

/**
 * Get referrer source code for logging
 * @return string
 */
private function get_referrer_source() {

    if (!empty($_GET['utm_source'])) {
        return '[UTM:' . strtoupper($_GET['utm_source']) . ']';
    }

    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    if (!$referrer) return '[DR]';

    $host = parse_url($referrer, PHP_URL_HOST) ?? '';

    $sources = [
        '[GL]'  => ['google.', 'googlebot'],
        '[FB]'  => ['facebook.', 'fb.me', 'l.facebook'],
        '[INS]' => ['instagram.', 'l.instagram'],
        '[TW]'  => ['twitter.', 'x.com', 't.co'],
        '[LI]'  => ['linkedin.'],
        '[PIN]' => ['pinterest.'],
        '[TT]'  => ['tiktok.'],
        '[YT]'  => ['youtube.'],
        '[BG]'  => ['bing.'],
        '[YA]'  => ['yandex.'],
        '[DDG]' => ['duckduckgo.'],
        '[EM]'  => ['mail.', 'outlook.', 'gmail.', 'yahoo.']
    ];

    foreach ($sources as $code => $patterns) {
        foreach ($patterns as $pattern) {
            if (stripos($host, $pattern) !== false) {
                return $code;
            }
        }
    }

    return '[RF]';
}

}
