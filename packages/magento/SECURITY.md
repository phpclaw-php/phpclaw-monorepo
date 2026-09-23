# Security Policy

## Supported Versions

The latest release on the current line is supported.

## Reporting a Vulnerability

Please **do not** report security vulnerabilities via GitHub issues.

Email: **er.akashpatel1908@gmail.com**

Include in your report:
- A description of the vulnerability
- Steps to reproduce
- Magento version, PHP version, module version
- Any proof-of-concept code (if applicable)

We will acknowledge your report within **48 hours** and aim to release a fix within **14 days** for critical issues.

## Scope

In scope:
- SQL injection, XSS, CSRF vulnerabilities in module code
- Prompt injection bypasses that reach the AI provider
- ACL bypass on `PhpClaw_Magento::phpclaw_*` resources: admin routes (`/admin/phpclaw/*`) or REST API routes (`/V1/phpclaw/*`) that circumvent the `ADMIN_RESOURCE` check declared on each controller
- Sensitive data leakage via REST endpoints (`/V1/phpclaw/*`) or adminhtml controllers
- SSRF via the `base_url` setting (used for custom provider or Ollama host) or any other URL input accepted by the module
- `db_query` tool SELECT-only enforcement bypass, guarded by `SqlReadOnlyGuard` and a restricted-identifier blocklist; any bypass that permits writes or reads sensitive columns (e.g. `api_key`, `password`, `secret`) is in scope

Out of scope:
- Vulnerabilities in third-party LLM providers (Anthropic, OpenAI, etc.)
- Vulnerabilities in Magento core or Adobe Commerce
- Denial-of-service via unlimited API key spend (mitigated by `max_iterations` + char limits)
