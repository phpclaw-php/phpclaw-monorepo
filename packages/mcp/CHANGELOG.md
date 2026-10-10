# phpClaw MCP

The Model Context Protocol server for phpClaw. Exposes your application's tools to Claude Desktop,
Cursor and any other MCP client, over stdio or a token-authenticated localhost HTTP transport, with
an allow and deny policy over which tools are reachable. PHP 8.1 and later.

## 0.1.3 (2026-10-09)

### Fixed
- Log a PHPCLAW_GUARDS class that does not exist (#140)

## 0.1.2 (2026-10-07)

### Added
- Mark tools that change data with destructiveHint (#84)

## 0.1.1 (2026-09-29)

- Maintenance release.

## 0.1.0 (2026-09-20)

- Initial release.
