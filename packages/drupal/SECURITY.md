# Security Policy

## Supported Versions

The latest release on the current line is supported.

## Reporting a Vulnerability

Please **do not** report security vulnerabilities via GitHub issues.

Email: **er.akashpatel1908@gmail.com**

Include in your report:
- A description of the vulnerability
- Steps to reproduce
- Drupal version, PHP version, module version
- Any proof-of-concept code (if applicable)

We will acknowledge your report within **48 hours** and aim to release a fix within **14 days** for critical issues.

## Scope

In scope:
- SQL injection, XSS, CSRF vulnerabilities in module code
- Prompt injection bypasses that reach the AI provider
- Permission bypasses on `/admin/config/phpclaw/*` routes or `phpclaw.*` services
- Sensitive data leakage via REST endpoints, Drush commands, or admin AJAX handlers
- SSRF via the custom provider `base_url` field or any URL input

Out of scope:
- Vulnerabilities in third-party AI providers (Anthropic, OpenAI, etc.)
- Vulnerabilities in Drupal core or contributed modules
- Denial-of-service via unlimited API key spend (mitigated by `max_iterations` + char limits)
