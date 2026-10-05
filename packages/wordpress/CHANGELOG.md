# phpClaw for WordPress

An AI agent inside your WordPress admin. Ask it in plain English about posts, users, comments,
media, menus, taxonomies, options, plugins, cron events and the debug log, and, when WooCommerce is
installed, about products, orders, customers, coupons, reviews, shipping, tax and stock. It answers
from your own site data. Works on WordPress 6.2 and later.

## 0.1.3 (2026-10-05)

### Added
- Add agent primitives: prompt templates, structured output, fallback, rate limit, response cache, token budget (#52)
- Send payloads for the 4 new agent primitive events (#54)
- Settings for fallback, rate limit, response cache and token budget (#56)
- Memory search and configurable memory recall (#80)

### Fixed
- OpenAI tool format for deepseek and custom, stable provider order (#62)
- Settings page parity for the agent primitives (#64)

## 0.1.2 (2026-09-29)

### Added
- Model-profile tool routing and skill context, core code standard (#5)

### Fixed
- Tool safety defaults, skills on demand, guard and shell hardening (#8)
- Send run_id and parent_run_id on guard and shell events (#10)
- Power tools opt-in, WooCommerce tool input fixes (#20)
- StoreMessages(false) stops memory writes in plain core (#50)

## 0.1.1 (2026-09-24)

- Dependency update: core 0.1.1.

## 0.1.0 (2026-09-20)

- Initial release.
