<div class="wrap" style="margin-top: 20px;">
    <div class="card-content" style="display: flex; justify-content: space-between; align-items: center; padding: 0 0; margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 24px;">🛡️</span>
            <span style="font-size: 18px; color: #666; font-weight: 600;">
                <?php _e('Bot Killer Dashboard', 'bot-killer'); ?>
            </span>
        </div>
    </div>

    <style>
        .log-google-bot, .log-bing-bot {
            background: #fff3e0;
            border-left-color: #ff9800;
        }

        .log-cart-bot {
            background: #e3f2fd;
            border-left-color: #1976d2;
        }

        .bot-killer-stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 10px;
            margin: 10px 0;
        }

        .bot-killer-stat-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            transition: transform 0.2s ease;
        }

        .bot-killer-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .bot-killer-stat-title {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #666;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .bot-killer-stat-value {
            font-size: 28px;
            font-weight: bold;
            margin: 0;
            line-height: 1.2;
        }

        .bot-killer-stat-label {
            font-size: 13px;
            color: #999;
            margin: 5px 0 0 0;
        }

        .bot-killer-main-grid {
            position: relative;
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 10px;
            margin: 10px 0;
        }

        .bot-killer-card {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .bot-killer-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f1;
        }

        .bot-killer-card-header h2 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .bot-killer-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .bot-killer-badge-red {
            background: #ffebee;
            color: #d63638;
        }

        .bot-killer-badge-green {
            background: #e8f5e9;
            color: #46b450;
        }

        .bot-killer-badge-blue {
            background: #e3f2fd;
            color: #1976d2;
        }

        .bot-killer-badge-orange {
            background: #fff3e0;
            color: #f57c00;
        }

        .bot-killer-badge-purple {
            background: #f3e5f5;
            color: #9333ea;
        }

        .bot-killer-btn {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 13px;
            text-decoration: none;
            cursor: pointer;
            border: none;
            outline: none;
            color: #ffffff;
            transition: background-color 0.2s ease, transform 0.2s ease;
        }

        .bot-killer-btn:focus,
        .bot-killer-btn:active,
        .bot-killer-btn:focus-visible {
            outline: none !important;
            border: none !important;
            box-shadow: none !important;
            color: #ffffff !important;
        }

        .bot-killer-btn,
        .bot-killer-btn:link,
        .bot-killer-btn:visited,
        .bot-killer-btn:hover,
        .bot-killer-btn:active,
        .bot-killer-btn:focus,
        .bot-killer-btn:focus-visible {
            color: #ffffff !important;
            text-decoration: none !important;
        }

        .bot-killer-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .bot-killer-btn:active {
            transform: translateY(0);
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.25);
        }

        .bot-killer-btn-grey {
            background-color: #94a3b8;
        }
        .bot-killer-btn-grey:hover {
            background-color: #64748b;
        }

        .bot-killer-btn-green {
            background-color: #a8cf83;
        }
        .bot-killer-btn-green:hover {
            background-color: #7fb65c;
        }

        .bot-killer-btn-blue {
            background-color: #60a5fa;
        }
        .bot-killer-btn-blue:hover {
            background-color: #3b82f6;
        }

        .bot-killer-btn-red {
            background-color: #fb7185;
        }
        .bot-killer-btn-red:hover {
            background-color: #f43f5e;
        }

        .bot-killer-btn-small {
            padding: 4px 12px;
            font-size: 12px;
        }

        .bot-killer-rule-list {
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .bot-killer-rule-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f1;
        }

        .bot-killer-rule-item:last-child {
            border-bottom: none;
        }

        .bot-killer-rule-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            font-size: 14px;
        }

        .bot-killer-rule-content {
            flex: 1;
        }

        .bot-killer-rule-title {
            font-weight: 600;
            margin-bottom: 3px;
        }

        .bot-killer-rule-desc {
            font-size: 13px;
            color: #666;
        }

        .bot-killer-priority-box {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .bot-killer-priority-list {
            margin: 10px 0 0 0;
            padding-left: 20px;
            font-size: 13px;
            color: #666;
        }

        .bot-killer-priority-list li {
            margin-bottom: 5px;
        }

        .bot-killer-table-container {
            height: 603px;
            overflow-y: auto !important;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            background: white;
        }

        .bot-killer-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .bot-killer-table th {
            background: #f9fafc;
            padding: 12px 8px;
            text-align: left;
            font-weight: 600;
            color: #444;
            border-bottom: 2px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .bot-killer-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #f0f0f1;
        }

        .bot-killer-table tr:hover td {
            background: #f9fafc;
        }

        .bot-killer-ip {
            font-weight: 600;
            color: #2271b1;
        }

        .bot-killer-time-remaining {
            color: #f57c00;
            font-weight: 500;
        }

        .bot-killer-footer {
            margin-top: 20px;
            text-align: right;
            font-size: 12px;
            color: #666;
        }
        
        .priority-inner-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 10px;
        }
        
        .red-cross, .green-ok {
            font-size: 14px;
            font-weight: 500;
        }
        
        .red-cross {
            color: #ff2337;
        }
        
        .red-cross::before {
            content: "✖ ";
        }
        
        .green-ok {
            color: #64B461;
        }
        
        .green-ok::before {
            content: "✔ ";
        }
    </style>

    <!-- Main Content Grid -->
    <div class="bot-killer-main-grid">
        <!-- Auto-blocked IPs Card -->
        <div class="bot-killer-card">
            <div class="bot-killer-card-header">
                <h2><?php _e('Auto-blocked IPs', 'bot-killer'); ?></h2>
                <div style="display: flex; gap: 8px;">
                    <button type="button" class="bot-killer-btn bot-killer-btn-blue" onclick="location.reload();">
                        <?php _e('Refresh IP List', 'bot-killer'); ?>
                    </button>
                    
                    <form method="post" style="margin: 0; display: inline;">
                        <?php wp_nonce_field('clear_all'); ?>
                        <button type="submit" name="clear_all" class="bot-killer-btn bot-killer-btn-red" onclick="return confirm('<?php _e('Remove all auto-blocks? This action cannot be undone.', 'bot-killer'); ?>');">
                            <?php _e('Unblock All IP', 'bot-killer'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <?php if (!empty($blocked_ips)): ?>
                <div class="bot-killer-table-container" style="margin-bottom: 18px;">
                    <table class="bot-killer-table">
                        <thead>
                            <tr>
                                <th><?php _e('IP Address', 'bot-killer'); ?></th>
                                <th><?php _e('Location', 'bot-killer'); ?></th>
                                <th><?php _e('Blocked', 'bot-killer'); ?></th>
                                <th><?php _e('Unblocks In', 'bot-killer'); ?></th>
                                <th><?php _e('Action', 'bot-killer'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($blocked_ips as $ip):
                                $block_info = isset($block_meta[$ip]) ? $block_meta[$ip] : null;
                                $unblock_time = $block_info ? $block_info['unblock_at'] : null;
                                $time_remaining = $unblock_time ? $unblock_time - time() : 0;
                                $geo = $block_info['geo'] ?? null;
                                if ($time_remaining < 0) $time_remaining = 0;
                                $hours = floor($time_remaining / 3600);
                                $minutes = floor(($time_remaining % 3600) / 60);
                                $row_bg = $time_remaining < 3600 ? 'background: #fff3e0;' : '';
                            ?>
                            <tr style="border-bottom: 1px solid #f0f0f1; <?php echo $row_bg; ?>">
                                <td style="padding: 10px 8px; color: #2271b1;"><?php echo esc_html($ip); ?></td>
                                <td style="padding: 10px 8px; color: #666;">
                                    <?php if ($geo && isset($geo['country_code'])): ?>
                                        <span style="background: #e3f2fd; padding: 2px 6px; border-radius: 4px; font-size: 11px; color: #1976d2;"><?php echo esc_html($geo['country_code']); ?></span>
                                        <?php echo esc_html($geo['city'] ?? __('unknown', 'bot-killer')); ?>
                                    <?php else: ?>
                                        <span style="color: #999;"><?php _e('unknown', 'bot-killer'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 8px; color: #666;">
                                    <?php if ($block_info && isset($block_info['blocked_at_readable'])): ?>
                                        <?php 
                                        $block_date = DateTime::createFromFormat('Y-m-d H:i:s', $block_info['blocked_at_readable']);
                                        echo $block_date ? $block_date->format('M j, H:i') : esc_html($block_info['blocked_at_readable']);
                                        ?>
                                    <?php else: ?>
                                        <span style="color: #999;"><?php _e('unknown', 'bot-killer'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 8px;">
                                    <?php if ($block_info): ?>
                                        <?php if ($time_remaining > 0): ?>
                                            <span style="color: #f57c00;"><?php echo $hours; ?>h <?php echo $minutes; ?>m</span>
                                            <?php if ($hours < 1): ?>
                                                <span style="font-size: 11px; background: #fff3e0; padding: 2px 6px; border-radius: 12px; color: #f57c00;"><?php _e('expiring soon', 'bot-killer'); ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #999; font-style: italic;"><?php _e('expired', 'bot-killer'); ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #999;"><?php _e('permanent', 'bot-killer'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding: 10px 8px;">
                                    <form method="post" style="margin: 0;">
                                        <?php wp_nonce_field('unblock_action'); ?>
                                        <input type="hidden" name="unblock_ip" value="<?php echo esc_attr($ip); ?>">
                                        <button type="submit" class="bot-killer-btn bot-killer-btn-red" style="padding: 4px 12px; font-size: 11px;" onclick="return confirm('<?php _e('Unblock this IP?', 'bot-killer'); ?>');">
                                            <?php _e('Unblock', 'bot-killer'); ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Footer Stats -->
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: #666; background: #f9f9f9; padding: 10px 15px; border-radius: 6px;">
                    <div style="display: flex; gap: 10px;">
                        <span><strong style="color: #d63638;"><?php echo count($blocked_ips); ?></strong> <?php _e('total blocked', 'bot-killer'); ?></span>
                        <span><strong style="color: #2271b1;"><?php echo esc_html($unblock_hours); ?>h</strong> <?php _e('auto-unblock', 'bot-killer'); ?></span>
                        <span><strong style="color: #f57c00;"><?php echo array_sum(array_map(function($ip) use ($block_meta) { 
                                $info = isset($block_meta[$ip]) ? $block_meta[$ip] : null;
                                return ($info && isset($info['unblock_at']) && $info['unblock_at'] - time() < 3600) ? 1 : 0;
                            }, $blocked_ips)); ?></strong> <?php _e('expiring in < 1h', 'bot-killer'); ?></span>
                        <span><strong style="color: #64748b;"><?php echo array_sum(array_map(function($ip) use ($block_meta) { 
                                return isset($block_meta[$ip]) ? 0 : 1; 
                            }, $blocked_ips)); ?></strong> <?php _e('permanent', 'bot-killer'); ?></span>
                    </div>
                    <div>
                        <span><?php _e('Timezone:', 'bot-killer'); ?> GMT<?php echo esc_html($this->timezone_offset); ?></span>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Empty State -->
                <div style="text-align: center; padding: 60px 20px; background: #f9fafc; border-radius: 8px; border: 1px dashed #e5e7eb;">
                    <span style="font-size: 64px; opacity: 0.5;">🛡️</span>
                    <p style="font-size: 16px; color: #666; margin: 20px 0 10px;"><?php _e('No auto-blocked IPs yet', 'bot-killer'); ?></p>
                    <p style="font-size: 13px; color: #999;"><?php _e('When bots are detected, they will appear here', 'bot-killer'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        
    <!-- Rules Summary Card -->
    <div class="bot-killer-card">
        <div class="bot-killer-card-header">
            <h2><?php _e('Active Rules', 'bot-killer'); ?></h2>
            <div>
                <a href="?page=bot-killer-settings" class="bot-killer-btn bot-killer-btn-green"><?php _e('Go to Settings', 'bot-killer'); ?></a>
            </div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <ul class="bot-killer-rule-list">
                <!-- Whitelist -->
                <li class="bot-killer-rule-item">
                    <div class="bot-killer-rule-icon" style="background: #e8f5e9; color: #7fb65c;">✓</div>
                    <div class="bot-killer-rule-content">
                        <div class="bot-killer-rule-title"><?php _e('Whitelist', 'bot-killer'); ?></div>
                        <div class="bot-killer-rule-desc"><?php echo $custom_whitelist_count; ?> <?php _e('IP ranges bypass all rules', 'bot-killer'); ?></div>
                    </div>
                    <span class="green-ok"></span>
                </li>
                
                <!-- Blocklist -->
                <li class="bot-killer-rule-item">
                    <div class="bot-killer-rule-icon" style="background: #e8f5e9; color: #d63638;">✗</div>
                    <div class="bot-killer-rule-content">
                        <div class="bot-killer-rule-title"><?php _e('Blocklist', 'bot-killer'); ?></div>
                        <div class="bot-killer-rule-desc"><?php echo $custom_blocked_count; ?> <?php _e('IP ranges manually blocked', 'bot-killer'); ?></div>
                    </div>
                    <span class="<?php echo ($custom_blocked_count > 0) ? 'green-ok' : 'red-cross'; ?>"></span>
                </li>
                <!-- ASN Blocklist -->
                <li class="bot-killer-rule-item">
                    <div class="bot-killer-rule-icon" style="background: #e8f5e9; color: #ff9800;">🌐</div>
                    <div class="bot-killer-rule-content">
                        <div class="bot-killer-rule-title"><?php _e('ASN Blocklist', 'bot-killer'); ?></div>
                        <div class="bot-killer-rule-desc">
                            <?php 
                            $raw_asn_text = get_option('bot_killer_asn_raw', '');
                            $asn_count = 0;
                            if (!empty($raw_asn_text)) {
                                $lines = explode("\n", $raw_asn_text);
                                foreach ($lines as $line) {
                                    $line = trim($line);
                                    if (empty($line) || strpos($line, '#') === 0) continue;
                                    if (preg_match('/[0-9]/', $line)) $asn_count++;
                                }
                            }
                            echo $asn_count . ' ' . __('networks blocked', 'bot-killer');
                            ?>
                        </div>
                    </div>
                    <span class="<?php echo ($asn_count > 0) ? 'green-ok' : 'red-cross'; ?>"></span>
                </li>
                <!-- Auto-blocked IPs -->
                <li class="bot-killer-rule-item">
                    <div class="bot-killer-rule-icon" style="background: #e8f5e9; color: #d63638;">🚫</div>
                    <div class="bot-killer-rule-content">
                        <div class="bot-killer-rule-title"><?php _e('Auto-blocked IPs', 'bot-killer'); ?></div>
                        <div class="bot-killer-rule-desc"><?php echo count($blocked_ips); ?> <?php _e('IPs automatically blocked', 'bot-killer'); ?></div>
                    </div>
                    <span class="<?php echo (count($blocked_ips) > 0) ? 'green-ok' : 'red-cross'; ?>"></span>
                </li>
                
                <!-- Country Filter -->
                <li class="bot-killer-rule-item">
                    <div class="bot-killer-rule-icon" style="background: #e8f5e9; color: #2271b1;">🌍</div>
                    <div class="bot-killer-rule-content">
                        <div class="bot-killer-rule-title"><?php _e('Country Filter', 'bot-killer'); ?></div>
                        <div class="bot-killer-rule-desc">
                            <?php 
                            if (!empty($allowed_countries)) {
                                echo __('countries allowed:', 'bot-killer') . ' ' . implode(', ', $allowed_countries);
                                if ($block_unknown) echo ' (' . __('block unknown', 'bot-killer') . ')';
                            } else {
                                _e('All countries allowed', 'bot-killer');
                                if ($block_unknown) echo ' (' . __('block unknown', 'bot-killer') . ')';
                            }
                            ?>
                        </div>
                    </div>
                    <span class="<?php echo (!empty($allowed_countries) || $block_unknown) ? 'green-ok' : 'red-cross'; ?>"></span>
                </li>
            </ul>
            
            <ul class="bot-killer-rule-list">
                <!-- Tor Exit Node -->
                <li class="bot-killer-rule-item">
                    <div class="bot-killer-rule-icon" style="background: #e8f5e9; color: #8e44ad;">🧅</div>
                    <div class="bot-killer-rule-content">
                        <div class="bot-killer-rule-title"><?php _e('Tor Exit Node', 'bot-killer'); ?></div>
                        <div class="bot-killer-rule-desc">
                            <?php 
                            $tor_ips = get_transient('bot_killer_tor_nodes');
                            $tor_count = $tor_ips ? count($tor_ips) : 0;
                            echo $tor_count . ' ' . __('nodes blocked', 'bot-killer');
                            ?>
                        </div>
                    </div>
                    <span class="<?php echo ($block_tor && $tor_count > 0) ? 'green-ok' : 'red-cross'; ?>"></span>
                </li>
                
                <!-- Out of Stock -->
                <li class="bot-killer-rule-item">
                    <div class="bot-killer-rule-icon" style="background: #e8f5e9; color: #ff9800;">📦</div>
                    <div class="bot-killer-rule-content">
                        <div class="bot-killer-rule-title"><?php _e('Out of Stock Blocker', 'bot-killer'); ?></div>
                        <div class="bot-killer-rule-desc"><?php _e('Block out-of-stock attempts', 'bot-killer'); ?></div>
                    </div>
                    <span class="<?php echo $block_out_of_stock ? 'green-ok' : 'red-cross'; ?>"></span>
                </li>
                
                <!-- Headless Detection -->
                <li class="bot-killer-rule-item">
                    <div class="bot-killer-rule-icon" style="background: #e8f5e9; color: #9c27b0;">🤖️</div>
                    <div class="bot-killer-rule-content">
                        <div class="bot-killer-rule-title"><?php _e('Headless Detection', 'bot-killer'); ?></div>
                        <div class="bot-killer-rule-desc"><?php _e('Blocks headless browsers', 'bot-killer'); ?></div>
                    </div>
                    <span class="<?php echo $block_headless ? 'green-ok' : 'red-cross'; ?>"></span>
                </li>
                
                <!-- Browser Integrity -->
                <li class="bot-killer-rule-item">
                    <div class="bot-killer-rule-icon" style="background: #e8f5e9; color: #ff9800;">🛡️</div>
                    <div class="bot-killer-rule-content">
                        <div class="bot-killer-rule-title"><?php _e('Browser Integrity', 'bot-killer'); ?></div>
                        <div class="bot-killer-rule-desc"><?php _e('JS, Cookies, Referer check', 'bot-killer'); ?></div>
                    </div>
                    <span class="<?php echo $block_browser_integrity ? 'green-ok' : 'red-cross'; ?>"></span>
                </li>
                
                <!-- Custom Rules -->
                <li class="bot-killer-rule-item">
                    <div class="bot-killer-rule-icon" style="background: #e8f5e9; color: #ff9800;">🧠</div>
                    <div class="bot-killer-rule-content">
                        <div class="bot-killer-rule-title"><?php _e('Custom Rules', 'bot-killer'); ?></div>
                        <div class="bot-killer-rule-desc"><?php _e('User-defined blocking rules', 'bot-killer'); ?></div>
                    </div>
                    <span class="<?php echo $custom_rules_enabled ? 'green-ok' : 'red-cross'; ?>"></span>
                </li>
                
                
            </ul>
        </div>
        
        <!-- Add-to-Cart Bots (full width) -->
        <li class="bot-killer-rule-item" style="list-style: none; padding: 8px 0; margin-top: 10px; border-top: 1px solid #f0f0f1;">
            <div class="bot-killer-rule-icon" style="width: 22px; height: 22px; background: #e8f5e9; color: #1976d2; border-radius: 6px; display: flex; align-items: center; justify-content: center;">🛒</div>
            <div class="bot-killer-rule-content">
                <div class="bot-killer-rule-title"><?php _e('Add-to-Cart Bots', 'bot-killer'); ?></div>
                <div class="bot-killer-rule-desc">
                    <?php _e('Google, Bing, Facebook, OpenAI, Anthropic, Telegram, Gemini, Perplexity, Baidu, Yandex, DuckDuckGo, LinkedIn, Pinterest, Twitter, Discord, Slack, CCBot, Amazonbot, Applebot, Bytespider, YouBot, Ahrefs, Semrush, MJ12, DotBot, Meta AI, TikTok, Seznam, PetalBot, WhatsApp, Mistral, Grok, DeepSeek, Qwen', 'bot-killer'); ?>
                </div>
            </div>
            <span class="green-ok"></span>
        </li>
    
        <div class="bot-killer-priority-box">
            <strong style="display: block; margin-bottom: 5px;"><?php _e('Priority Order:', 'bot-killer'); ?></strong>
            <div class="priority-inner-grid">
                <ol class="bot-killer-priority-list">
                    <li><?php _e('Whitelist', 'bot-killer'); ?></li>
                    <li><?php _e('Custom Blocklist', 'bot-killer'); ?></li>
                    <li><?php _e('Auto-blocked', 'bot-killer'); ?></li>
                    <li><?php _e('Add-to-Cart Bots', 'bot-killer'); ?></li>
                </ol>
                <ol class="bot-killer-priority-list" start="5">
                    <li><?php _e('Tor Exit Node', 'bot-killer'); ?></li>
                    <li><?php _e('ASN Block', 'bot-killer'); ?></li>
                    <li><?php _e('Headless Detection', 'bot-killer'); ?></li>
                    <li><?php _e('Browser Integrity', 'bot-killer'); ?></li>
                </ol>
                <ol class="bot-killer-priority-list" start="9">
                    <li><?php _e('Country Filter', 'bot-killer'); ?></li>
                    <li><?php _e('Out of Stock', 'bot-killer'); ?></li>
                    <li><?php _e('Custom Rules', 'bot-killer'); ?></li>
                </ol>
            </div>
        </div>
    </div>
    </div>

    <!-- Live Log Section -->
    <div style="background: #fff; padding: 20px; border-radius: 8px; margin: 10px 0; border: 1px solid #e5e7eb;">
        <div class="bot-killer-card-header">
            <h2 style="margin:0; font-size: 18px;"><?php _e('Live Log', 'bot-killer'); ?></h2>
            <div style="display: flex; gap: 5px;">
                <button type="button" class="bot-killer-btn bot-killer-btn-blue" onclick="location.reload();"><?php _e('Refresh Log', 'bot-killer'); ?></button>
                <form method="post" style="margin: 0; display: inline;">
                    <?php wp_nonce_field('clear_log'); ?>
                    <button type="submit" name="clear_log" class="bot-killer-btn bot-killer-btn-grey"><?php _e('Empty Log', 'bot-killer'); ?></button>
                </form>
            </div>
        </div>

        <style>
            .log-row {padding: 6px 10px; margin: 1px 0; border-left: 4px solid; font-size: 13px; font-family: monospace; line-height: 1.2; color: #333;}
            .log-first-add, .log-add-to-cart, .log-admin-add {background: #f1f8e9; border-left-color: #689f38;}
            .log-attempt {background: #fff9c4; border-left-color: #ffc107;}
            .log-blocked, .log-tor-blocked {background:#ffebee; border-left-color:#e53935;}
            .log-whitelist {background: #e1f5fe; border-left-color: #03a9f4;}
            .log-admin-action {background: #ffd6dc; border-left-color: #c2185b;}
            .log-custom-rule, .log-spoof-attempt, .log-out-of-stock, .log-browser-failed, .log-headless {background:#fff3e0; border-left-color:#fb8c00;}
            .log-default {background: #f5f5f5; border-left-color: #9e9e9e;}
            .log-cart-bot {background: #e3f2fd; border-left-color: #1976d2;}
            .log-purchase, .log-purchase-item, .log-search-engine {background: #c8e6c9; border-left-color: #2e7d32;}
            .log-remove-cart {background: #fff3e0; border-left-color: #ff9800;}
            .log-asn-blocked {background: #fff3e0; border-left-color: #e67e22;}
            .log-bot-allowed {background: #f5fbff; border-left-color: #03a9f4;}
        </style>
        
        <div style="max-height: 651px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; background: white;">
            <div style="display: flex; flex-direction: column;">
                <?php
                $display_count = 0;
                foreach ($log_lines as $line) {
                    if (empty($line) || strpos($line, '---') !== false) continue;
                    if (strpos($line, '[') === 0) {
                        $log_class = 'log-row log-default';
                        
                        // =============================================
                        // BLOCK 1: BOTS (all types)
                        // =============================================
                        $bot_patterns = [
                            'GOOGLE', 'BING', 'YANDEX', 'FACEBOOK', 'AHREFS', 'SEMRUSH',
                            'OPENAI', 'ANTHROPIC', 'TELEGRAM', 'GEMINI', 'PERPLEXITY',
                            'APPLEBOT', 'CCBOT', 'AMAZONBOT', 'BYTESPIDER', 'YOUBOT',
                            'SEZNAM', 'PETALBOT', 'TIKTOK', 'META AI', 'DOTBOT', 'MJ12BOT',
                            'LINKEDIN', 'PINTEREST', 'TWITTER', 'DISCORD', 'SLACK',
                            'CLOUDFLARE', 'BAIDU', 'DUCKDUCKGO', 'WHATSAPP', 'MICROSOFTPREVIEW',
                            'MISTRAL', 'GROK', 'DEEPSEEK', 'QWEN'
                        ];
                        
                        $is_bot_log = false;
                        $is_bot_allowed = false;
                        $is_spoof = false;
                        
                        foreach ($bot_patterns as $bot) {
                            if (strpos($line, $bot . ' bot detected') !== false || 
                                strpos($line, $bot . ' verified bot') !== false) {
                                $is_bot_log = true;
                                $is_bot_allowed = false;
                                $is_spoof = false;
                                break;
                            }
                            if (strpos($line, $bot . ' granted') !== false) {  
                                $is_bot_log = true;
                                $is_bot_allowed = true;
                                $is_spoof = false;
                                break;
                            }
                        }                   

                        $rules = [
                        
                            // --- BLOCKED (highest priority) ---
                            'log-row log-blocked' => [
                                'ip blocked - SPOOF ATTEMPT',
                                'ip blocked',
                                'access attempt blocked',
                                'blocked by custom blocklist',
                                'blocked - country',
                                'Tor exit node detected',
                                'already in auto-block list',
                            ],
                        
                            // --- SPOOF ---
                            'log-row log-spoof-attempt' => [
                                'SPOOF ATTEMPT',
                                'verification FAILED',
                            ],
                        
                            // --- ASN ---
                            'log-row log-asn-blocked' => [
                                ['ASN ', 'blocked'], // must contain BOTH
                            ],
                        
                            // --- SECURITY ---
                            'log-row log-browser-failed' => [
                                'Browser integrity check failed',
                            ],
                        
                            'log-row log-headless' => [
                                'Headless browser detected',
                            ],
                        
                            // --- BOTS ---
                            'log-row log-cart-bot' => [
                                'bot detected',
                                'verified bot',
                            ],
                        
                            'log-row log-bot-allowed' => [
                                ' granted ',
                            ],
                        
                            // --- CART ---
                            'log-row log-admin-add' => [
                                ['ADD TO CART - Product ID:', '[ADMIN]'],
                            ],
                        
                            'log-row log-add-to-cart' => [
                                'ADD TO CART',
                            ],
                        
                            'log-row log-remove-cart' => [
                                'REMOVE FROM CART',
                            ],
                        
                            'log-row log-purchase' => [
                                'PURCHASE',
                            ],
                        
                            // --- STOCK ---
                            'log-row log-out-of-stock' => [
                                'out-of-stock',
                                'out of stock',
                            ],
                        
                            // --- ATTEMPTS ---
                            'log-row log-first-add' => [
                                'first add',
                            ],
                        
                            'log-row log-attempt' => [
                                'attempt #',
                            ],
                        
                            // --- ADMIN ---
                            'log-row log-admin-action' => [
                                'manually unblocked',
                                'auto-unblocked',
                            ],
                        
                            'log-row log-custom-rule' => [
                                'custom rule',
                            ],
                        
                            'log-row log-whitelist' => [
                                'whitelisted',
                            ],
                        ];
                        
                        $log_class = 'log-row log-default';
                        
                        foreach ($rules as $class => $conditions) {
                            foreach ($conditions as $condition) {
                        
                                // Multiple required matches
                                if (is_array($condition)) {
                                    $match = true;
                        
                                    foreach ($condition as $part) {
                                        if (stripos($line, $part) === false) {
                                            $match = false;
                                            break;
                                        }
                                    }
                        
                                    if ($match) {
                                        $log_class = $class;
                                        break 2;
                                    }
                        
                                // Single match
                                } else {
                                    if (stripos($line, $condition) !== false) {
                                        $log_class = $class;
                                        break 2;
                                    }
                                }
                            }
                        }

                        echo '<div class="' . esc_attr($log_class) . '">' . esc_html($line) . '</div>';
                        $display_count++;
                    }
                }
                if ($display_count == 0) {
                    echo '<div class="log-row log-default" style="padding: 40px; text-align: center;">';
                    echo '<span style="font-size: 48px;">📭</span><br>' . __('No log entries yet', 'bot-killer');
                    echo '</div>';
                }
                ?>
            </div>
        </div>
        
        <div class="bot-killer-footer" style="background: #f9f9f9; padding: 5px 10px; border-radius: 6px; margin-top: 10px;">
            <span><?php printf(__('timestamps in GMT%s | total displayed: %d | Geo data: [country code - city]', 'bot-killer'), esc_html($this->timezone_offset), $display_count); ?></span>
        </div>
    </div>
</div>