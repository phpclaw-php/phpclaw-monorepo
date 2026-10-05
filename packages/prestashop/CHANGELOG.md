# phpClaw for PrestaShop

An AI agent inside your PrestaShop back office. Ask it in plain English about products, orders,
customers, categories, carts, coupons, stock, manufacturers, employees, modules, configuration and
sales reports, and it answers from your own store data. Works on PrestaShop 8.0 and later, and 9.x.

## 0.1.3 (2026-10-05)

### Added
- Add agent primitives: prompt templates, structured output, fallback, rate limit, response cache, token budget (#52)
- Send payloads for the 4 new agent primitive events (#54)
- Fallback, rate limit, response cache and token budget settings (#70)
- Memory search and configurable memory recall (#80)

### Fixed
- OpenAI tool format for deepseek and custom, stable provider order (#62)

## 0.1.2 (2026-09-29)

### Added
- Model-profile tool routing and skill context, core code standard (#5)

### Fixed
- Tool safety defaults, skills on demand, guard and shell hardening (#8)
- Send run_id and parent_run_id on guard and shell events (#10)
- Power tools opt-in, MCP tools built as non-interactive (#14)
- StoreMessages(false) stops memory writes in plain core (#50)

## 0.1.1 (2026-09-24)

- Dependency update: core 0.1.1.

## 0.1.0 (2026-09-20)

- Initial release.
