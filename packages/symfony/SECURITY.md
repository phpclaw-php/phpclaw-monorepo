# Security Policy

## Supported Versions

The latest release on the current line is supported.

## Reporting a Vulnerability

Please **do not** report security vulnerabilities via GitHub issues.

Email: **er.akashpatel1908@gmail.com**

Include in your report:
- A description of the vulnerability
- Steps to reproduce
- Symfony version, PHP version, phpclaw-symfony version
- Any proof-of-concept code (if applicable)

We will acknowledge your report within **48 hours** and aim to release a fix within **14 days** for critical issues.

## Scope

In scope:
- SQL injection, XSS, CSRF vulnerabilities in bundle code
- Prompt injection bypasses that reach the AI provider
- Authentication or authorisation bypasses on the 2 REST endpoints (`/phpclaw/send`, `/phpclaw/chat/stream`), which `TokenAuthListener` guards by requiring the request to resolve to a logged-in user through the host application's security firewall; an unauthenticated caller is refused with 401, another user's `conversation_id` with 403, and `PHPCLAW_API_ENABLED=false` closes both routes with 403
- Approval gate bypass: mutating tool calls over HTTP are blocked because `CliApprovalGate` checks `stream_isatty(STDIN)` and throws `HumanDeniedException` when no interactive TTY is present; any bypass that allows mutating tool execution in a web request context is in scope
- `DatabaseTool` query-type bypass: queries are validated as SELECT-only by `SqlReadOnlyGuard` and additionally blocked against 10 restricted identifiers (`password`, `api_key`, `access_token`, etc.); a bypass permitting write queries or credential column access is in scope
- Sensitive data leakage via REST endpoints, console commands, or the Web Profiler panel: `PhpClawDataCollector` applies pattern-based redaction to tool inputs before storing them, but the patterns may not cover every credential format
- SSRF via the `custom` provider `base_url` field or any URL input
- `phpclaw.booting` event listener injection: any installed Symfony bundle can register a listener that appends tools, guards, or hooks to the `PhpClawExtensions` bag at boot; an exploit that uses this surface to register unauthorised capabilities is in scope

Out of scope:
- Vulnerabilities in third-party AI providers (Anthropic, OpenAI, etc.)
- Vulnerabilities in Symfony framework core, Doctrine DBAL, or Symfony Messenger
- Denial-of-service via unlimited API key spend (mitigated by `max_iterations` + char limits)
