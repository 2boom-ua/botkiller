<div class="wrap">
    <!-- Action Buttons -->
    <div class="card-content" style="display: flex; justify-content: space-between; align-items: center; padding: 0 0; margin-bottom: 10px;">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 24px;">⚙️</span>
            <span style="font-size: 18px; color: #666; font-weight: 600;">
                <?php _e('BotKiller Settings', 'bot-killer'); ?>
            </span>
        </div>
        
        <div style="display: flex; gap: 10px;">
            <a href="?page=bot-killer" class="bot-killer-btn bot-killer-btn-blue">
                <?php _e('Return to Dashboard', 'bot-killer'); ?>
            </a>
        </div>
    </div>

<style>
.bot-killer-settings-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10px;
    margin: 10px 0;
}

.bot-killer-card {
    background: #fff;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #e5e7eb;
}

.bot-killer-card h2 {
    margin: 0 0 15px 0;
    font-size: 16px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f1;
}

.bot-killer-card .card-content {
    padding: 5px 0;
}

.bot-killer-rule-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #f0f0f1;
}

.bot-killer-rule-row:last-child {
    border-bottom: none;
}

.bot-killer-rule-title {
    font-weight: 500;
    color: #1e1e1e;
}

.bot-killer-toggle {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.bot-killer-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}

.bot-killer-toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .3s;
    border-radius: 24px;
}

.bot-killer-toggle-slider:before {
    position: absolute;
    content: "";
    height: 20px;
    width: 20px;
    left: 2px;
    bottom: 2px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}

input:checked + .bot-killer-toggle-slider {
    background-color: #7fb65c;
}

input:checked + .bot-killer-toggle-slider:before {
    transform: translateX(26px);
}

.bot-killer-input-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.bot-killer-input-group input[type="number"] {
    width: 70px;
    padding: 5px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.bot-killer-textarea {
    width: 100%;
    font-family: monospace;
    font-size: 13px;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #f9f9f9;
}

.bot-killer-textarea-red {
    background: #fff5f5;
    border-color: #f7caca;
}

.bot-killer-textarea-green {
    background: #f0f9f0;
    border-color: #b7e1b7;
}

.bot-killer-textarea-orange {
    background: #fff6eb;
    border-color: #f0cfa6;
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
    color: #7fb65c;
}

.bot-killer-badge-blue {
    background: #e3f2fd;
    color: #1976d2;
}

.bot-killer-badge-orange {
    background: #fff3e0;
    color: #f57c00;
}

/* ===============================
   BOT KILLER BUTTON – BASE
================================ */
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

a.bot-killer-btn.bot-killer-btn:hover,
a.bot-killer-btn.bot-killer-btn:focus,
a.bot-killer-btn.bot-killer-btn:active {
    color: #ffffff !important;
}

.bot-killer-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.bot-killer-btn:active {
    transform: translateY(0);
    box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.25);
}

/* GREY */
.bot-killer-btn-grey {
    background-color: #94a3b8;
}
.bot-killer-btn-grey:hover {
    background-color: #64748b;
}

/* GREEN */
.bot-killer-btn-green {
    background-color: #a8cf83;
}
.bot-killer-btn-green:hover {
    background-color: #7fb65c;
}

/* BLUE */
.bot-killer-btn-blue {
    background-color: #60a5fa;
}
.bot-killer-btn-blue:hover {
    background-color: #3b82f6;
}

/* ORANGE */
.bot-killer-btn-orange {
    background-color: #f7943a;
}
.bot-killer-btn-orange:hover {
    background-color: #f6821f;
}

/* PURPLE */
.bot-killer-btn-purple {
    background-color: #9b59b6;
}
.bot-killer-btn-purple:hover {
    background-color: #8e44ad;
}

/* RED */
.bot-killer-btn-red {
    background-color: #fb7185;
}
.bot-killer-btn-red:hover {
    background-color: #f43f5e;
}

.bot-killer-help-text {
    margin: 5px 0 0 0;
    font-size: 12px;
    color: #666;
}

/* Checked states per color */
.pastel-blue input:checked + .bot-killer-toggle-slider {
    background-color: #a5d8ff;
}
.pastel-green input:checked + .bot-killer-toggle-slider {
    background-color: #a3d9a5;
}
.pastel-orange input:checked + .bot-killer-toggle-slider {
    background-color: #ffd6a5;
}
.pastel-purple input:checked + .bot-killer-toggle-slider {
    background-color: #d9a5ff;
}
.pastel-red input:checked + .bot-killer-toggle-slider {
    background-color: #ffb3b3;
}

.bot-killer-textarea-red:focus {
    border-color: #e07a7a !important;
    box-shadow: 0 0 0 1px #e07a7a !important;
    outline: none !important;
}

.bot-killer-textarea-green:focus {
    border-color: #b7e1b7 !important;
    box-shadow: 0 0 0 1px #5cb85c !important;
    outline: none !important;
}

.bot-killer-textarea-orange:focus {
    border-color: #f17223 !important;
    box-shadow: 0 0 0 1px #f17223 !important;
    outline: none !important;
}
</style>

<!-- IP & ASN Access Control Section -->
<div class="bot-killer-settings-grid">
    <!-- Blocklist Card -->
    <form method="post" style="padding:0; background:none; border:none;">
        <?php wp_nonce_field('bot_killer_ip_lists'); ?>
        <input type="hidden" name="save_blocklist" value="1">
        <div class="bot-killer-card">
            <h2 style="color: #d63638;"><?php _e('Blocklist', 'bot-killer'); ?></h2>
            <div class="card-content">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                    <div style="background: #fff5f5; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; color: #5f6368;"><?php _e('Active Blocks', 'bot-killer'); ?></div>
                        <div style="font-size: 20px; font-weight: bold; color: #d63638;" id="blocklist-count">
                            <?php echo number_format($custom_blocked_count); ?>
                        </div>
                        <div style="font-size: 11px; color: #5f6368;"><?php _e('IP ranges', 'bot-killer'); ?></div>
                    </div>
                </div>
                
                <textarea name="blocklist_ips" rows="8" class="bot-killer-textarea bot-killer-textarea-red"><?php echo esc_textarea($block_ips); ?></textarea>
                <p class="bot-killer-help-text">
                    <?php _e('Single IP, CIDR, range, or IPv6. Use # for comments.', 'bot-killer'); ?>
                </p>
                
                <div style="display: flex; align-items: center; justify-content: space-between; background: #f9f9f9; padding: 12px; border-radius: 6px;">
                    <span style="font-size: 12px; color: #666;">
                        <?php _e('Last saved:', 'bot-killer'); ?> 
                        <span id="blocklist-saved">
                            <?php
                            if (file_exists($this->custom_block_file)) {
                                echo human_time_diff(filemtime($this->custom_block_file), time()) . ' ' . __('ago', 'bot-killer');
                            } else {
                                _e('Never', 'bot-killer');
                            }
                            ?>
                        </span>
                    </span>
                    <button type="submit" class="bot-killer-btn bot-killer-btn-red"><?php _e('Save Blocklist', 'bot-killer'); ?></button>
                </div>
            </div>
        </div>
    </form>
    
    <!-- Whitelist Card -->
    <form method="post" style="padding:0; background:none; border:none;">
        <?php wp_nonce_field('bot_killer_ip_lists'); ?>
        <input type="hidden" name="save_whitelist" value="1">
        <div class="bot-killer-card">
            <h2 style="color: #7fb65c;"><?php _e('Whitelist', 'bot-killer'); ?></h2>
            <div class="card-content">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                    <div style="background: #f0f9f0; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; color: #5f6368;"><?php _e('Whitelisted', 'bot-killer'); ?></div>
                        <div style="font-size: 20px; font-weight: bold; color: #7fb65c;" id="whitelist-count">
                            <?php echo number_format($custom_whitelist_count); ?>
                        </div>
                        <div style="font-size: 11px; color: #5f6368;"><?php _e('IP ranges', 'bot-killer'); ?></div>
                    </div>
                </div>
                
                <textarea name="whitelist_ips" rows="8" class="bot-killer-textarea bot-killer-textarea-green"><?php echo esc_textarea($white_ips); ?></textarea>
                <p class="bot-killer-help-text">
                    <?php _e('IPs that bypass all blocking rules. Use # for comments.', 'bot-killer'); ?>
                </p>
                
                <div style="display: flex; align-items: center; justify-content: space-between; background: #f9f9f9; padding: 12px; border-radius: 6px;">
                    <span style="font-size: 12px; color: #666;">
                        <?php _e('Last saved:', 'bot-killer'); ?> 
                        <span id="whitelist-saved">
                            <?php
                            if (file_exists($this->custom_white_file)) {
                                echo human_time_diff(filemtime($this->custom_white_file), time()) . ' ' . __('ago', 'bot-killer');
                            } else {
                                _e('Never', 'bot-killer');
                            }
                            ?>
                        </span>
                    </span>
                    <button type="submit" class="bot-killer-btn bot-killer-btn-green"><?php _e('Save Whitelist', 'bot-killer'); ?></button>
                </div>
            </div>
        </div>
    </form>
    
    <!-- ASN Blocklist Card -->
    <form method="post" style="padding:0; background:none; border:none;">
        <?php wp_nonce_field('bot_killer_asn_action', 'bot_killer_asn_nonce'); ?>
        <input type="hidden" name="save_asn_list" value="1">
        <div class="bot-killer-card">
            <h2 style="color: #e67e22;"><?php _e('ASN Blocklist', 'bot-killer'); ?></h2>
            <div class="card-content">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                    <div style="background: #fff6eb; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; color: #5f6368;"><?php _e('Blocked ASNs', 'bot-killer'); ?></div>
                        <div style="font-size: 20px; font-weight: bold; color: #e67e22;" id="asn-count">
                            <?php 
                            $raw_asn_text = get_option('bot_killer_asn_raw', '');
                            $asn_count = 0;
                            if (!empty($raw_asn_text)) {
                                $lines = explode("\n", $raw_asn_text);
                                foreach ($lines as $line) {
                                    $line = trim($line);
                                    if (empty($line) || strpos($line, '#') === 0) continue;
                                    $number_part = strpos($line, '#') !== false ? substr($line, 0, strpos($line, '#')) : $line;
                                    $asn = preg_replace('/[^0-9]/', '', $number_part);
                                    if (!empty($asn) && is_numeric($asn)) $asn_count++;
                                }
                            }
                            echo number_format($asn_count);
                            ?>
                        </div>
                        <div style="font-size: 11px; color: #5f6368;"><?php _e('networks', 'bot-killer'); ?></div>
                    </div>
                </div>
                
                <textarea name="asn_raw" rows="8" class="bot-killer-textarea bot-killer-textarea-orange"><?php echo esc_textarea(get_option('bot_killer_asn_raw', '')); ?></textarea>
                <p class="bot-killer-help-text">
                    <?php _e('ASN numbers (13335, 16509). Use # for comments.', 'bot-killer'); ?>
                </p>
                
                <div style="display: flex; align-items: center; justify-content: space-between; background: #f9f9f9; padding: 12px; border-radius: 6px;">
                    <span style="font-size: 12px; color: #666;">
                        <?php _e('Last saved:', 'bot-killer'); ?> 
                        <span id="asn-saved">
                            <?php
                            $asn_updated = get_option('bot_killer_asn_last_updated', 0);
                            echo $asn_updated > 0 ? human_time_diff($asn_updated, time()) . ' ' . __('ago', 'bot-killer') : __('Never', 'bot-killer');
                            ?>
                        </span>
                    </span>
                    <button type="submit" class="bot-killer-btn bot-killer-btn-orange"><?php _e('Save ASN List', 'bot-killer'); ?></button>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Settings Form -->
<form method="post" action="options.php">
    <?php 
    settings_fields('bot_killer_settings');
    do_settings_sections('bot_killer_settings');
    ?>
    
    <div class="bot-killer-settings-grid"> 
        <!-- Bot IP Ranges Card -->
        <div class="bot-killer-card">
            <h2 style="color: #7fb65c;"><?php _e('Bot, Search Engines and Cloudflare', 'bot-killer'); ?></h2>
            <div class="card-content">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                    <div style="background: #f0f9f0; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; color: #5f6368;"><?php _e('Bot Types', 'bot-killer'); ?></div>
                        <div style="font-size: 20px; font-weight: bold; color: #7fb65c;">37</div>
                        <div style="font-size: 11px; color: #5f6368;"><?php _e('supported', 'bot-killer'); ?></div>
                    </div>
                    <div style="background: #f0f9f0; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; color: #5f6368;"><?php _e('Update Frequency', 'bot-killer'); ?></div>
                        <div style="font-size: 20px; font-weight: bold; color: #7fb65c;"><?php _e('Weekly', 'bot-killer'); ?></div>
                        <div style="font-size: 11px; color: #5f6368;"><?php _e('via cron', 'bot-killer'); ?></div>
                    </div>
                    <div style="background: #f0f9f0; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; color: #5f6368;"><?php _e('Cache Duration', 'bot-killer'); ?></div>
                        <div style="font-size: 20px; font-weight: bold; color: #7fb65c;">7d</div>
                        <div style="font-size: 11px; color: #5f6368;"><?php _e('IP addresses', 'bot-killer'); ?></div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                    <div style="background: #f0f9f0; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; font-weight: 600; color: #4285f4; margin-bottom: 5px;">Google</div>
                        <span style="font-size: 20px; font-weight: bold; color: #7fb65c;"><?php echo number_format($google_count); ?></span>
                        <span style="font-size: 11px; color: #5f6368; margin-left: 5px;">IP ranges</span>
                    </div>
                    <div style="background: #f0f9f0; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; font-weight: 600; color: #8e44ad; margin-bottom: 5px;">Bing</div>
                        <span style="font-size: 20px; font-weight: bold; color: #7fb65c;"><?php echo number_format($bing_count); ?></span>
                        <span style="font-size: 11px; color: #5f6368; margin-left: 5px;">IP ranges</span>
                    </div>
                    <div style="background: #f0f9f0; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; font-weight: 600; color: #f6821f; margin-bottom: 5px;">Cloudflare</div>
                        <span style="font-size: 18px; font-weight: bold; color: #7fb65c;"><?php echo number_format($cloudflare_v4); ?></span> <span style="font-size: 11px; color: #5f6368;">IPv4</span>
                        <span style="margin-left: 10px; font-size: 18px; font-weight: bold; color: #7fb65c;"><?php echo number_format($cloudflare_v6); ?></span> <span style="font-size: 11px; color: #5f6368;">IPv6</span>
                    </div>
                </div>
                

                <p class="bot-killer-help-text">
                    <?php _e('All IP ranges are automatically updated weekly via cron.', 'bot-killer'); ?>
                </p>

                <div style="display: flex; align-items: center; justify-content: space-between; background: #f9f9f9; padding: 12px; border-radius: 6px;">
                    <span style="font-size: 12px; color: #666;">
                        <?php _e('Last update:', 'bot-killer'); ?> 
                        <span id="bot-ranges-last-update">
                            <?php
                            $last_update = get_option('bot_killer_last_bot_update', 0);
                            echo $last_update > 0 ? human_time_diff($last_update, time()) . ' ' . __('ago', 'bot-killer') : __('Never', 'bot-killer');
                            ?>
                        </span>
                    </span>
                    <button type="button" class="bot-killer-btn bot-killer-btn-green" onclick="updateAllBotRanges(this)">
                        <?php _e('Update All IP Ranges', 'bot-killer'); ?>
                    </button>
                </div>
            </div>
        </div>
    
        <!-- Tor Exit Node Card -->
        <div class="bot-killer-card">
            <h2 style="color: #8e44ad;"><?php _e('Tor Exit Nodes', 'bot-killer'); ?></h2>
            <div class="card-content">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                    <div style="background: #fcf4fb; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; color: #5f6368;"><?php _e('Active Nodes', 'bot-killer'); ?></div>
                        <div style="font-size: 20px; font-weight: bold; color: #8e44ad;" id="tor-node-count">
                            <?php 
                            $tor_ips = get_transient('bot_killer_tor_nodes');
                            echo $tor_ips ? number_format(count($tor_ips)) : '0';
                            ?>
                        </div>
                        <div style="font-size: 11px; color: #5f6368;"><?php _e('IP addresses', 'bot-killer'); ?></div>
                    </div>
                    <div style="background: #fcf4fb; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; color: #5f6368;"><?php _e('Update Frequency', 'bot-killer'); ?></div>
                        <div style="font-size: 20px; font-weight: bold; color: #8e44ad;">12h</div>
                        <div style="font-size: 11px; color: #5f6368;"><?php _e('via cron', 'bot-killer'); ?></div>
                    </div>
                </div>
                
                <div class="bot-killer-rule-row">
                    <span class="bot-killer-rule-title"><?php _e('Block Tor exit nodes', 'bot-killer'); ?></span>
                    <label class="bot-killer-toggle pastel-purple">
                        <input type="checkbox" name="bot_killer_block_tor" value="1" <?php checked($block_tor, 1); ?>>
                        <span class="bot-killer-toggle-slider"></span>
                    </label>
                </div>
                
                <p class="bot-killer-help-text" style="margin-top: 33px;"><?php _e('Blocks visitors using Tor anonymizer network. Updated every 12 hours.', 'bot-killer'); ?></p>
        
                <div style="display: flex; align-items: center; justify-content: space-between; background: #f9f9f9; padding: 10px; border-radius: 6px;">
                    <span style="font-size: 12px; color: #666;">
                        <?php _e('Last update:', 'bot-killer'); ?> 
                        <span id="tor-last-update">
                            <?php
                            $tor_ips = get_transient('bot_killer_tor_nodes');
                            $tor_timeout = get_option('_transient_timeout_bot_killer_tor_nodes', 0);
                            
                            if ($tor_ips !== false && $tor_timeout > time()) {
                                $update_time = $tor_timeout - (12 * HOUR_IN_SECONDS); // 12 hours
                                echo human_time_diff($update_time, time()) . ' ' . __('ago', 'bot-killer');
                            } else {
                                _e('Never', 'bot-killer');
                            }
                            ?>
                        </span>
                    </span>
                    <button type="button" class="bot-killer-btn bot-killer-btn-purple" onclick="updateTorNodes(this)">
                        <?php _e('Update Tor Nodes', 'bot-killer'); ?>
                    </button>
                </div>
            </div>
        </div>
        <!-- Browser Integrity & Out of Stock Blocker Card -->
<div class="bot-killer-card">
    <!-- Browser Integrity Section -->
    <h2 style="display: flex; align-items: center; gap: 10px; margin-bottom: 15px;">
    <span style="color: #7fb65c;"><?php _e('Browser Integrity', 'bot-killer'); ?></span>
    <span style="color: #ccc;">|</span>
    <span style="color: #4285f4;"><?php _e('Out of Stock Blocker', 'bot-killer'); ?></span>
    </h2>
    <div class="card-content" style="padding: 0;">
        <div class="bot-killer-rule-row">
            <span class="bot-killer-rule-title"><?php _e('Block missing JS/cookies/referer', 'bot-killer'); ?></span>
            <label class="bot-killer-toggle pastel-green">
                <input type="checkbox" name="bot_killer_block_browser_integrity" value="1" <?php checked($block_browser_integrity, 1); ?>>
                <span class="bot-killer-toggle-slider"></span>
            </label>
        </div>
        
        <div class="bot-killer-rule-row" style="border-top: 1px dashed #e0e0e0;">
            <span class="bot-killer-rule-title"><?php _e('Block headless browsers', 'bot-killer'); ?></span>
            <label class="bot-killer-toggle pastel-green">
                <input type="checkbox" name="bot_killer_block_headless" value="1" <?php checked($block_headless, 1); ?>>
                <span class="bot-killer-toggle-slider"></span>
            </label>
        </div>
        
        <p class="bot-killer-help-text" style="margin-top: 10px; margin-bottom: 30px;">
            <?php _e('Blocks bots missing JavaScript, cookies, or referer headers.', 'bot-killer'); ?>
        </p>
    </div>

    <!-- Divider -->
    <div style="height: 1px; background: #f0f0f1; margin: 15px 0;"></div>

    <!-- Out of Stock Blocker Section -->
    <div class="card-content" style="padding: 0;">
        <div class="bot-killer-rule-row">
            <span class="bot-killer-rule-title"><?php _e('Block out of stock attempts', 'bot-killer'); ?></span>
            <label class="bot-killer-toggle pastel-blue">
                <input type="checkbox" name="bot_killer_block_out_of_stock" value="1" <?php checked($block_out_of_stock, 1); ?>>
                <span class="bot-killer-toggle-slider"></span>
            </label>
        </div>
        
        <p class="bot-killer-help-text" style="margin-top: 10px;">
            <?php _e('When a visitor tries to add an out-of-stock product to cart, their IP is immediately blocked.', 'bot-killer'); ?>
        </p>
    </div>
</div>
        
    </div>

    <!-- Country Blocking & GeoIP Configuration -->
    <div class="bot-killer-settings-grid">
        <!-- Country Blocking Card -->
        <div class="bot-killer-card">
            <h2 style="color: #2271b1;"><?php _e('Country Access Control', 'bot-killer'); ?></h2>
            <div class="card-content">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                    <div style="background: #f1f6fc; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; color: #5f6368;"><?php _e('Allowed Countries', 'bot-killer'); ?></div>
                        <div style="font-size: 20px; font-weight: bold; color: #2271b1;"><?php echo number_format(count($allowed_countries)); ?></div>
                        <div style="font-size: 11px; color: #5f6368;"><?php _e('selected', 'bot-killer'); ?></div>
                    </div>
                </div>
                
                <div class="bot-killer-rule-row">
                    <span class="bot-killer-rule-title"><?php _e('Block unknown countries', 'bot-killer'); ?></span>
                    <label class="bot-killer-toggle pastel-blue">
                        <input type="checkbox" name="bot_killer_block_unknown_country" value="1" <?php checked($block_unknown, 1); ?>>
                        <span class="bot-killer-toggle-slider"></span>
                    </label>
                </div>

                <div style=" margin-top: 10px;">
                    <label style="font-weight: 600; display: block; margin-bottom: 10px;"><?php _e('Allow add-to-cart only from:', 'bot-killer'); ?></label>
                    <select name="bot_killer_allowed_countries[]" multiple style="width: 100%; height: 102px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                        <?php
                            $all_countries = [
                                // Western Europe
                                'GB' => __('United Kingdom', 'bot-killer'), 'IE' => __('Ireland', 'bot-killer'), 'NL' => __('Netherlands', 'bot-killer'),
                                'BE' => __('Belgium', 'bot-killer'), 'LU' => __('Luxembourg', 'bot-killer'), 'FR' => __('France', 'bot-killer'),
                                'MC' => __('Monaco', 'bot-killer'),
                                
                                // Central Europe
                                'DE' => __('Germany', 'bot-killer'), 'AT' => __('Austria', 'bot-killer'), 'CH' => __('Switzerland', 'bot-killer'),
                                'LI' => __('Liechtenstein', 'bot-killer'), 'PL' => __('Poland', 'bot-killer'), 'CZ' => __('Czech Republic', 'bot-killer'),
                                'SK' => __('Slovakia', 'bot-killer'), 'HU' => __('Hungary', 'bot-killer'), 'SI' => __('Slovenia', 'bot-killer'),
                                
                                // Eastern Europe
                                'EE' => __('Estonia', 'bot-killer'), 'LV' => __('Latvia', 'bot-killer'), 'LT' => __('Lithuania', 'bot-killer'),
                                'BY' => __('Belarus', 'bot-killer'), 'UA' => __('Ukraine', 'bot-killer'), 'MD' => __('Moldova', 'bot-killer'),
                                'GE' => __('Georgia', 'bot-killer'), 'AM' => __('Armenia', 'bot-killer'), 'AZ' => __('Azerbaijan', 'bot-killer'),
                                
                                // Southern Europe
                                'PT' => __('Portugal', 'bot-killer'), 'ES' => __('Spain', 'bot-killer'), 'IT' => __('Italy', 'bot-killer'),
                                'MT' => __('Malta', 'bot-killer'), 'GR' => __('Greece', 'bot-killer'), 'CY' => __('Cyprus', 'bot-killer'),
                                'HR' => __('Croatia', 'bot-killer'), 'BA' => __('Bosnia and Herzegovina', 'bot-killer'), 'RS' => __('Serbia', 'bot-killer'),
                                'ME' => __('Montenegro', 'bot-killer'), 'MK' => __('North Macedonia', 'bot-killer'), 'AL' => __('Albania', 'bot-killer'),
                                'BG' => __('Bulgaria', 'bot-killer'), 'RO' => __('Romania', 'bot-killer'), 'TR' => __('Turkey', 'bot-killer'),
                                
                                // Northern Europe
                                'DK' => __('Denmark', 'bot-killer'), 'FI' => __('Finland', 'bot-killer'), 'IS' => __('Iceland', 'bot-killer'),
                                'NO' => __('Norway', 'bot-killer'), 'SE' => __('Sweden', 'bot-killer'),
                                
                                // North America
                                'CA' => __('Canada', 'bot-killer'), 'US' => __('United States', 'bot-killer'), 'MX' => __('Mexico', 'bot-killer'),
                                
                                // South America (short)
                                'BR' => __('Brazil', 'bot-killer'), 'AR' => __('Argentina', 'bot-killer'), 'CL' => __('Chile', 'bot-killer'),
                                
                                // Asia (short)
                                'IL' => __('Israel', 'bot-killer'), 'AE' => __('United Arab Emirates', 'bot-killer'), 'JP' => __('Japan', 'bot-killer'),
                                'CN' => __('China', 'bot-killer'), 'IN' => __('India', 'bot-killer'), 'KR' => __('South Korea', 'bot-killer'),
                                
                                // Africa (short)
                                'ZA' => __('South Africa', 'bot-killer'), 'NG' => __('Nigeria', 'bot-killer'), 'EG' => __('Egypt', 'bot-killer'),
                                
                                // Oceania (short)
                                'AU' => __('Australia', 'bot-killer'), 'NZ' => __('New Zealand', 'bot-killer')
                            ];
                        asort($all_countries);
                        foreach ($all_countries as $code => $name) {
                            $selected = in_array($code, $allowed_countries) ? 'selected' : '';
                            echo '<option value="' . esc_attr($code) . '" ' . $selected . '>' . esc_html($name) . ' (' . esc_html($code) . ')</option>';
                        }
                        ?>
                    </select>
                    <p class="bot-killer-help-text" style="margin-top: 10px;"><?php _e('Hold Ctrl/Cmd to select multiple. Leave empty to allow all.', 'bot-killer'); ?></p>
                </div>
                
                
            </div>
        </div>
    
        <!-- GeoIP Configuration Card -->
        <div class="bot-killer-card">
            <h2 style="color: #64748b;"><?php _e('GeoIP Service', 'bot-killer'); ?></h2>
            <div class="card-content">
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                    <div style="background: #f0f4f8; padding: 10px 15px; border-radius: 8px; flex: 1;">
                        <div style="font-size: 13px; color: #5f6368;"><?php _e('Cache Duration', 'bot-killer'); ?></div>
                        <div style="font-size: 20px; font-weight: bold; color: #64748b;"><?php echo $geoip_cache_hours; ?>h</div>
                        <div style="font-size: 11px; color: #5f6368;"><?php _e('cached', 'bot-killer'); ?></div>
                    </div>
                </div>

                <div class="bot-killer-rule-row">
                    <span class="bot-killer-rule-title"><?php _e('Primary Service', 'bot-killer'); ?></span>
                    <select name="bot_killer_geoip_service" style="width: 140px; padding: 6px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="freegeoip" <?php selected($geoip_service, 'freegeoip'); ?>>freegeoip.app</option>
                        <option value="ip-api" <?php selected($geoip_service, 'ip-api'); ?>>ip-api.com</option>
                    </select>
                </div>
                
                <div class="bot-killer-rule-row">
                    <span class="bot-killer-rule-title"><?php _e('Cache Duration', 'bot-killer'); ?></span>
                    <select name="bot_killer_geoip_cache_hours" style="width: 120px; padding: 6px;">
                        <option value="1" <?php selected($geoip_cache_hours, 1); ?>>1 hour</option>
                        <option value="6" <?php selected($geoip_cache_hours, 6); ?>>6 hours</option>
                        <option value="12" <?php selected($geoip_cache_hours, 12); ?>>12 hours</option>
                        <option value="24" <?php selected($geoip_cache_hours, 24); ?>>24 hours</option>
                        <option value="168" <?php selected($geoip_cache_hours, 168); ?>>1 week</option>
                    </select>
                </div>
                
                <p class="bot-killer-help-text" style="margin-top: 18px;">
                    <?php _e('Geolocation provider, cache time, and fallback settings.', 'bot-killer'); ?>
                </p>
                <div style="background: #f9f9f9; padding: 15px; border-radius: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" name="bot_killer_geoip_fallback" value="1" <?php checked($geoip_fallback, 1); ?>>
                        <strong><?php _e('Enable fallback', 'bot-killer'); ?></strong>
                    </label>
                </div>
            </div>
        </div>
 <!-- Custom Rules Card -->
<div class="bot-killer-card">
    <h2 style="color: #f57c00;"><?php _e('Custom Rules', 'bot-killer'); ?></h2>
    <div class="card-content">
        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
            <div style="background: #fff3e0; padding: 10px 15px; border-radius: 8px; flex: 1;">
                <div style="font-size: 13px; color: #5f6368;"><?php _e('Active Rules', 'bot-killer'); ?></div>
                <div style="font-size: 20px; font-weight: bold; color: #f57c00;" id="custom-rules-count">
                    <?php 
                    $custom_count = 0;
                    if (!empty($custom_rules)) {
                        $lines = explode("\n", $custom_rules);
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if (!empty($line) && strpos($line, '#') !== 0 && strpos($line, ',') !== false) $custom_count++;
                        }
                    }
                    echo $custom_count;
                    ?>
                </div>
            </div>
        </div>

        <div class="bot-killer-rule-row">
            <span class="bot-killer-rule-title"><?php _e('Enable Custom Rules', 'bot-killer'); ?></span>
            <label class="bot-killer-toggle pastel-orange">
                <input type="checkbox" name="bot_killer_custom_rules_enabled" value="1" <?php checked($custom_rules_enabled, 1); ?>>
                <span class="bot-killer-toggle-slider"></span>
            </label>
        </div>
        
        <textarea name="bot_killer_custom_rules" rows="6" class="bot-killer-textarea bot-killer-textarea-orange"><?php 
            echo empty($custom_rules) ? "# 2 same products in 5 seconds\n2,5,0\n\n# 3 same products in 5 minutes\n3,300,0\n\n# 2 different products in 5 seconds\n2,5,1" : esc_textarea($custom_rules);
        ?></textarea>

        <p class="bot-killer-help-text" style="margin-top: 28px;">
            <?php _e('Format: attempts,seconds,type (0=same, 1=different, 2=any). Use # for comments.', 'bot-killer'); ?>
        </p>
        
        <!-- Save Button at bottom - like other cards -->
        <div style="margin-top: 1px; display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #f9f9f9; padding: 12px; border-radius: 6px;">
            <span style="font-size: 12px; color: #666;">
                <?php _e('Save your custom rules', 'bot-killer'); ?>
            </span>
            <button type="submit" name="save_settings" class="bot-killer-btn bot-killer-btn-orange">
                <?php _e('Save All Rules', 'bot-killer'); ?>
            </button>
        </div>
    </div>
</div>        
        
    </div>

    <!-- Rules Section -->
    <div class="bot-killer-settings-grid">
    <!-- System Settings Card - GREEN -->
    <div class="bot-killer-card">
        <h2 style="color: #7fb65c; display: flex; align-items: center; gap: 10px;">
            <?php _e('System Settings', 'bot-killer'); ?>
        </h2>
        <div class="card-content">
            <!-- Stats Row -->
            <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 15px;">
                <div style="background: #f0f9f0; padding: 10px 15px; border-radius: 8px; flex: 1;">
                    <div style="font-size: 13px; color: #5f6368;"><?php _e('Auto-unblock', 'bot-killer'); ?></div>
                    <div style="font-size: 20px; font-weight: bold; color: #7fb65c;">
                        <?php echo $unblock_hours; ?>h
                    </div>
                    <div style="font-size: 11px; color: #5f6368;"><?php _e('after block', 'bot-killer'); ?></div>
                </div>
                <div style="background: #f0f9f0; padding: 10px 15px; border-radius: 8px; flex: 1;">
                    <div style="font-size: 13px; color: #5f6368;"><?php _e('Max Log Size', 'bot-killer'); ?></div>
                    <div style="font-size: 20px; font-weight: bold; color: #7fb65c;">
                        <?php echo $max_log_size; ?> MB
                    </div>
                    <div style="font-size: 11px; color: #5f6368;"><?php _e('before rotation', 'bot-killer'); ?></div>
                </div>
            </div>
    
            <!-- IP Block Expiration Row -->
            <div class="bot-killer-rule-row">
                <span class="bot-killer-rule-title" style="display: flex; align-items: center; gap: 8px;">
                    <?php _e('Auto-unblock after', 'bot-killer'); ?>
                </span>
                <div class="bot-killer-input-group">
                    <input type="number" 
                        name="bot_killer_unblock_hours" 
                        value="<?php echo esc_attr($unblock_hours); ?>" 
                        min="1" 
                        max="720" 
                        style="width: 70px; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                    <span><?php _e('hours', 'bot-killer'); ?></span>
                </div>
            </div>
            
            <!-- Log Size Row -->
            <div class="bot-killer-rule-row">
                <span class="bot-killer-rule-title" style="display: flex; align-items: center; gap: 8px;">
                    <?php _e('Max log size', 'bot-killer'); ?>
                </span>
                <div class="bot-killer-input-group">
                    <input type="number" 
                        name="bot_killer_max_log_size" 
                        value="<?php echo esc_attr($max_log_size); ?>" 
                        min="1" 
                        max="1000" 
                        style="width: 70px; padding: 5px; border: 1px solid #ddd; border-radius: 4px;">
                    <span>MB</span>
                </div>
            </div>
            
            <!-- Timezone Row -->
            <div class="bot-killer-rule-row">
                <span class="bot-killer-rule-title" style="display: flex; align-items: center; gap: 8px;">
                    <?php _e('Time zone (GMT)', 'bot-killer'); ?>
                </span>
                <select name="bot_killer_timezone" style="width: 140px; padding: 6px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;">
                    <?php
                    $timezones = ['-12:00', '-11:00', '-10:00', '-09:00', '-08:00', '-07:00', '-06:00', '-05:00', '-04:00', '-03:00', '-02:00', '-01:00', '+00:00', '+01:00', '+02:00', '+03:00', '+03:30', '+04:00', '+04:30', '+05:00', '+05:30', '+05:45', '+06:00', '+06:30', '+07:00', '+08:00', '+08:45', '+09:00', '+09:30', '+10:00', '+10:30', '+11:00', '+12:00', '+12:45', '+13:00', '+14:00'];
                    foreach ($timezones as $tz) {
                        echo '<option value="' . esc_attr($tz) . '" ' . selected($timezone_offset, $tz, false) . '>GMT' . esc_html($tz) . '</option>';
                    }
                    ?>
                </select>
            </div>
            <p class="bot-killer-help-text">
                <?php _e('Configure auto-unblock time, log rotation, and timezone.', 'bot-killer'); ?>
            </p>
            
        </div>
    </div> 
    </div>

    <!-- Action Buttons -->
    <div class="card-content" style="display: flex; justify-content: flex-end; padding: 20px 0;">
        <div style="display: flex; gap: 10px;">
            <a href="?page=bot-killer" class="bot-killer-btn bot-killer-btn-blue"><?php _e('Return to Dashboard', 'bot-killer'); ?></a>
            <button type="submit" name="save_settings" class="bot-killer-btn bot-killer-btn-green"><?php _e('Save All Settings', 'bot-killer'); ?></button>
        </div>
    </div>
</form>
</div>

<script type="text/javascript">
window.updateTorNodes = function(buttonElement) {
    if (!confirm('<?php echo esc_js(__('Update Tor exit nodes?', 'bot-killer')); ?>')) return;
    
    var button = buttonElement;
    var originalText = button.textContent;
    button.textContent = '⏳ ...';
    button.disabled = true;
    
    var data = new FormData();
    data.append('action', 'bot_killer_update_tor_nodes');
    data.append('nonce', '<?php echo wp_create_nonce('bot_killer_ajax'); ?>');
    
    fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            button.textContent = '✓ ' + originalText;
            var timeSpan = document.getElementById('tor-last-update');
            if (timeSpan) {
                timeSpan.textContent = '<?php echo esc_js(__('just now', 'bot-killer')); ?>';
                timeSpan.style.color = '#7fb65c';
            }
            var countSpan = document.getElementById('tor-node-count');
            if (countSpan && data.data && data.data.count) countSpan.textContent = Number(data.data.count).toLocaleString();
            setTimeout(() => location.reload(), 2000);
        } else {
            button.textContent = '✗ ' + originalText;
            button.disabled = false;
            setTimeout(() => button.textContent = originalText, 2000);
        }
    })
    .catch(() => {
        button.textContent = '✗ ' + originalText;
        button.disabled = false;
        setTimeout(() => button.textContent = originalText, 2000);
    });
};

window.updateAllBotRanges = function(buttonElement) {
    if (!confirm('<?php echo esc_js(__('Update all bot IP ranges now?', 'bot-killer')); ?>')) return;
    
    var button = buttonElement;
    var originalText = button.textContent;
    button.textContent = '⏳ ...';
    button.disabled = true;
    
    var data = new FormData();
    data.append('action', 'bot_killer_update_all_bots');
    data.append('nonce', '<?php echo wp_create_nonce('bot_killer_ajax'); ?>');
    
    fetch(ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            button.textContent = '✓ ' + originalText;
            var timeSpan = document.getElementById('bot-ranges-last-update');
            if (timeSpan) {
                timeSpan.textContent = '<?php echo esc_js(__('just now', 'bot-killer')); ?>';
                timeSpan.style.color = '#7fb65c';
            }
            setTimeout(() => location.reload(), 2000);
        } else {
            button.textContent = '✗ ' + originalText;
            button.disabled = false;
            setTimeout(() => button.textContent = originalText, 2000);
        }
    })
    .catch(() => {
        button.textContent = '✗ ' + originalText;
        button.disabled = false;
        setTimeout(() => button.textContent = originalText, 2000);
    });
};
</script>