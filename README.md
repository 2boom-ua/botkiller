# Bot Killer for WooCommerce

Advanced multi-layer bot protection for WooCommerce

[![Version](https://img.shields.io/badge/version-2.9.7-green.svg)](https://github.com/yourusername/bot-killer)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-4.0+-blue.svg)](https://woocommerce.com)
[![WordPress](https://img.shields.io/badge/WordPress-5.0+-orange.svg)](https://wordpress.org)
[![License](https://img.shields.io/badge/license-GPLv2-red.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Description

Bot Killer for WooCommerce is a multi-layered security plugin designed to protect your store from bots, scrapers, spoofing attempts, and abusive traffic.

It combines DNS validation, IP range checks, ASN verification, and behavioral analysis to distinguish legitimate users from malicious traffic.

---

## Features

### Security Layers
- Whitelist and manual blocklist
- Auto-blocking system
- Rate limiting and custom rules
- ASN (network-level) blocking
- Tor exit node blocking (auto-updated)

### Detection
- 37+ supported bot types
- Headless browser detection (Puppeteer, Playwright, Selenium)
- AI crawler detection
- DNS and IP spoofing protection

### Access Control
- Country-based filtering (GeoIP)
- Browser integrity checks (JavaScript, cookies, referer)

### Monitoring
- Real-time live log with color coding
- Request classification
- Automatic IP unblock after timeout

---

## Installation

1. Upload plugin to: /wp-content/plugins/botKiller/
2. ctivate via WordPress Admin → Plugins
3. Open Bot Killer → Settings
4. Configure protection rules

---

## Configuration

- Blocklist / Whitelist  
Manual IP access control

- ASN Blocklist  
Block entire networks or providers

- Bot IP Ranges  
Trusted bot verification by IP

- Tor Exit Nodes  
Anonymous traffic blocking

- Custom Rules  
Rate limiting and behavior rules

- Browser Integrity  
Validate JavaScript, cookies, headers

- Country Filter  
Restrict access by location

- System  
Auto-unblock, logs, timezone

---

## Supported Bots (37+)

### Search Engines  
Web indexing and SEO crawling  
Google, Bing, Baidu, Yandex, DuckDuckGo, Seznam, PetalBot  

### Social Platforms  
Link previews and sharing crawlers  
Facebook, WhatsApp, LinkedIn, Pinterest, Twitter, Discord, Slack, Telegram, Microsoft  

### AI Crawlers  
AI training and data collection bots  
OpenAI, Anthropic, Perplexity, Gemini, Google AI, Bytespider, TikTok, Mistral, Grok, DeepSeek, Qwen, Meta AI, YouBot  

### SEO Tools  
Analytics and backlink scanners  
Ahrefs, Semrush, MJ12, DotBot  

### Cloud / Services  
Infrastructure and service crawlers  
Cloudflare, CCBot, Amazonbot, Applebot  

---

## Request Priority

1. Whitelist  
2. Manual Blocklist  
3. Auto-blocked IPs  
4. Verified Bots  
5. Tor Exit Nodes  
6. ASN Blocking  
7. Headless Detection  
8. Browser Integrity  
9. Country Filter  
10. Out-of-Stock Protection  
11. Custom Rules  

---

## Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.4+

---

## External Services

- freegeoip.app – GeoIP lookup (15,000 req/hour)
- Cloudflare – IP range updates
- Tor Project – Exit node list

---

## License

GPL v3 or later  
https://www.gnu.org/licenses/gpl-3.0.html
