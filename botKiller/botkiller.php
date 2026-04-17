<?php
/**
* Plugin Name: BOT KILLER
* Plugin URI: https://github.com/2boom-ua/botkiller/tree/main
* Description: Advanced bot protection for WooCommerce
* Version: 3.0.5
* Author: 2boom
* Text Domain: bot-killer
* Domain Path: /languages
* Requires at least: 5.0
* Tested up to: 6.4
* WC requires at least: 4.0
* WC tested up to: 8.0
* License: GPL v3 or later
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define Plugin Constants
define('BOTKILLER_PLUGIN_FILE', __FILE__);
define('BOTKILLER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BOTKILLER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BOTKILLER_VERSION', '3.0.5');

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'bot_killer_woocommerce_missing_notice');
    return;
}

/**
 * WooCommerce missing notice
 */
function bot_killer_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error">
        <p><?php _e('Bot Killer requires WooCommerce to be installed and activated.', 'bot-killer'); ?></p>
    </div>
    <?php
}

// Load text domain early
add_action('plugins_loaded', 'bot_killer_load_textdomain');
function bot_killer_load_textdomain() {
    load_plugin_textdomain('bot-killer', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

// Include Class
require_once BOTKILLER_PLUGIN_DIR . 'includes/class-botkiller.php';

// Instantiate Class
new BotKiller();