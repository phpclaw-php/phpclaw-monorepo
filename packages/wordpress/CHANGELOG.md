# phpClaw for WordPress

An AI agent inside your WordPress admin. Ask it in plain English about posts, users, comments,
media, menus, taxonomies, options, plugins, cron events and the debug log, and, when WooCommerce is
installed, about products, orders, customers, coupons, reviews, shipping, tax and stock. It answers
from your own site data. Works on WordPress 6.2 and later.

## 0.1.4 (2026-10-09)

### Added
- Pipelines, graphs and graph fan-out (#90)
- Trace payloads for graph events and parent run ids (#103)
- File_read line ranges and line numbers (#105)
- Optional redaction of personal data in tool results (#107)
- Classifier approval gate and model router (#144)
- Open the system prompt with a default phpClaw AI Agent identity (#146)

### Fixed
- File_edit approval prompt shows the file path (#91)
- A shell command the tool refuses no longer asks for approval first (#92)
- Code_search skips .env and secret files, the same as file_read (#93)
- Keep PHP tags in tool results so a PHP file read and written again stays valid (#94)
- Send Gemini thought signatures back with tool calls (#95)
- Gemini stream API key and skill docblocks (#109)
- Forget() deletes a conversation's messages too (#115)
- Project_info inspects ABSPATH wherever the process starts (#129)
- Log a PHPCLAW_GUARDS class that does not exist (#140)
- Treat http://[::1] as loopback (#141)
- Drop the phpunit Feature suite that points at a missing directory (#148)
- Keep cloud off when storeMessages is false (#156)

## 0.1.3 (2026-10-07)

### Added
- Add agent primitives: prompt templates, structured output, fallback, rate limit, response cache, token budget (#52)
- Send payloads for the 4 new agent primitive events (#54)
- Settings for fallback, rate limit, response cache and token budget (#56)
- Memory search and configurable memory recall (#80)
- Durable runs, pause-for-approval and run resumption (#82)
- Mark tools that change data with destructiveHint (#84)

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
