=== Bot Killer for WooCommerce ===
Contributors: yourusername
Tags: security, bot protection, woocommerce, firewall, anti spam, rate limit
Requires at least: 5.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.9.9
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Advanced multi-layer bot protection for WooCommerce. Blocks bots, scrapers, spoofing, and abusive traffic using IP, DNS, and ASN verification.

== Description ==

Bot Killer for WooCommerce is a multi-layered security plugin designed to protect your store from bots, scrapers, spoofing attempts, and abusive traffic.

It combines DNS validation, IP range checks, ASN verification, and behavioral analysis to distinguish legitimate users from malicious traffic.

Key features:

- Multi-layer protection system (whitelist, blocklist, auto-blocking)
- 37+ supported bot types (search, social, AI, SEO, cloud)
- Headless browser detection (Puppeteer, Playwright, Selenium)
- DNS and IP spoofing protection
- ASN blocking (network-level)
- Tor exit node blocking (auto-updated)
- Country filtering (GeoIP)
- Browser integrity checks (JavaScript, cookies, referer)
- Rate limiting and custom rules
- Real-time live log with request classification
- Automatic IP unblock after timeout

== Installation ==

1. Upload the plugin to `/wp-content/plugins/botKiller/`
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Go to "Bot Killer → Settings"
4. Configure protection rules

== Frequently Asked Questions ==

= Will this block real users? =

No. The plugin uses layered verification (IP, DNS, ASN, behavior) to minimize false positives.

= Does it support WooCommerce only? =

It is optimized for WooCommerce, but also protects general WordPress traffic.

= Does it block good bots like Google? =

No. Verified bots are detected using IP ranges and DNS validation and are allowed.

= Can I unblock IPs manually? =

Yes. You can manage whitelist and blocklist in settings.

= Does it slow down the website? =

No. The plugin is optimized for performance and uses caching where possible.

== Screenshots ==

1. Settings dashboard
2. Live log with color-coded entries
3. Bot detection rules
4. Country filter settings

== Changelog ==

= 2.9.7 =
* Improved bot detection accuracy
* Added new AI crawler signatures
* Performance optimizations
* Minor UI improvements

== Upgrade Notice ==

= 2.9.7 =
Update recommended for improved detection and stability.

== External Services ==

This plugin uses external services:

- freegeoip.app  
  Used for IP geolocation (limit: 15,000 requests/hour)  
  https://freegeoip.app/

- Cloudflare  
  Used to retrieve trusted IP ranges  
  https://www.cloudflare.com/

- Tor Project  
  Used to retrieve exit node list  
  https://check.torproject.org/

== License ==

This plugin is licensed under the GPL v3 or later.

https://www.gnu.org/licenses/gpl-3.0.html
