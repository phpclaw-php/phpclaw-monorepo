# phpClaw for WordPress

An AI agent inside your WordPress admin. Ask it in plain English about posts, users, comments,
media, menus, taxonomies, options, plugins, cron events and the debug log, and, when WooCommerce is
installed, about products, orders, customers, coupons, reviews, shipping, tax and stock. It answers
from your own site data. Works on WordPress 6.2 and later.

## 0.1.3 (2026-10-05)

- Dependency update: core 0.1.3.

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
