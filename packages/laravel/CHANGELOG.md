# phpClaw for Laravel

An AI agent inside your Laravel application. Ask it in plain English about your routes, config
values, cache store, queue health, application log and database, from Artisan, from your own code,
or over REST. Works on Laravel 10, 11, 12 and 13.

## 0.1.3 (2026-10-09)

### Added
- Interactive phpclaw:chat command and conversation flags on phpclaw (#123)

### Fixed
- Project_info inspects base_path() wherever the process starts (#128)

## 0.1.2 (2026-10-07)

### Added
- Config for fallback, rate limit, response cache and token budget (#58)
- Durable runs and pause-for-approval (#86)

### Fixed
- Explain the agent primitive settings in the config file (#72)

## 0.1.1 (2026-09-29)

### Fixed
- REST API refuses memory that cannot keep conversations per user (#24)

## 0.1.0 (2026-09-20)

- Initial release.
