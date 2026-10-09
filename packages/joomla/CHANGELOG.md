# phpClaw for Joomla

An AI agent inside your Joomla administrator. Ask it in plain English about articles, categories,
users and installed extensions, and it answers from your own site data. Works on Joomla 4, 5 and 6.

## 0.1.4 (2026-10-09)

- Dependency update: core 0.1.4, cloud 0.1.3.

## 0.1.3 (2026-10-07)

### Added
- Add agent primitives: prompt templates, structured output, fallback, rate limit, response cache, token budget (#52)
- Send payloads for the 4 new agent primitive events (#54)
- Fallback, rate limit, response cache and token budget settings (#66)
- Memory search and configurable memory recall (#80)
- Durable runs, pause-for-approval and run resumption (#82)
- Mark tools that change data with destructiveHint (#84)

### Fixed
- OpenAI tool format for deepseek and custom, stable provider order (#62)

## 0.1.2 (2026-09-29)

### Added
- Model-profile tool routing and skill context, core code standard (#5)

### Fixed
- Tool safety defaults, skills on demand, guard and shell hardening (#8)
- Send run_id and parent_run_id on guard and shell events (#10)
- Power tools opt-in, skill tests follow core (#22)
- StoreMessages(false) stops memory writes in plain core (#50)

## 0.1.1 (2026-09-24)

- Dependency update: core 0.1.1.

## 0.1.0 (2026-09-20)

- Initial release.
