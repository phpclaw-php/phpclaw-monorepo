# Security Policy

## Supported Versions

The latest release on the current line is supported.

## Reporting a Vulnerability

Please **do not** report security vulnerabilities via GitHub issues.

Email: **er.akashpatel1908@gmail.com**

Include in your report:
- A description of the vulnerability
- Steps to reproduce
- Laravel version, PHP version, phpclaw-laravel version
- Any proof-of-concept code (if applicable)

We will acknowledge your report within **48 hours** and aim to release a fix within **14 days** for critical issues.

## Scope

In scope:
- Authentication bypass on REST endpoints (`/phpclaw/send`, `/phpclaw/chat/stream`), for example a timing or token-comparison flaw in `AuthenticatePhpClawApi`
- `CliApprovalGate` bypass: any path that allows a mutating tool call to execute over HTTP without an interactive STDIN (the gate calls `stream_isatty(STDIN)` and throws `HumanDeniedException` when no tty is present; a bypass is in scope)
- SQL injection, XSS, or CSRF vulnerabilities in package code (controllers, memory drivers, queue jobs)
- Prompt injection bypasses that reach the AI provider
- Sensitive data leakage via REST API responses or Artisan command output
- SSRF via the custom provider `base_url` field (currently validated to allow HTTPS anywhere, and plain HTTP only for a loopback host) or any other URL input

Out of scope:
- Vulnerabilities in third-party AI providers (Anthropic, OpenAI, Groq, Gemini, etc.)
- Vulnerabilities in Laravel framework core
- Denial-of-service via unlimited API key spend (mitigated by `max_iterations` + char limits)
