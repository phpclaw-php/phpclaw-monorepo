# phpClaw Cloud

The cloud transport for phpClaw. Forwards agent runs to phpClaw Cloud for tracing and analytics, and
screens prompts through the cloud scan guard, with secret redaction and payload size caps applied
before anything leaves your server. Inert until a cloud key is set. PHP 8.1 and later.

## 0.1.1 (2026-09-29)

### Fixed
- Send run_id and parent_run_id on guard and shell events (#10)

## 0.1.0 (2026-09-20)

- Initial release.
