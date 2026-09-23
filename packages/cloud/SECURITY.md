# Security Policy

## Supported Versions

The latest release on the current line is supported.

## Reporting a Vulnerability

Please **do not** report security vulnerabilities via GitHub issues.

Email: **er.akashpatel1908@gmail.com**

Include in your report:
- A description of the vulnerability
- Steps to reproduce
- phpClaw cloud version, PHP version
- Any proof-of-concept code (if applicable)

We will acknowledge your report within **48 hours** and aim to release a fix within **14 days** for critical issues.

## Scope

In scope:
- SSRF or TLS downgrade against the `/v1/manifest`, `/v1/scan` and `/v1/runs` endpoints the cloud transport drives
- Leakage of API keys, credentials, or secrets in payloads sent to phpClaw Cloud by `CloudWebhookHook` (lifecycle event forwarding) or `CloudScanGuard` (prompt scanning)
- Bypass of `CloudPayloadBuilder`'s secret-key redaction, string-truncation, or payload-size caps that keep credentials and oversized data out of forwarded payloads
- `CloudWebhookHook` forwarding raw message/response content regardless of the host application's `store_messages` setting, since core's hook dispatcher does not filter payloads by `store_messages` (that boundary is enforced only at the memory layer via `PrivacyAwareMemory`), so this package's listener is the last line of defense for that content and currently forwards it unconditionally
- Forgery, replay, or signature bypass of signed `CloudScanGuard` scan verdicts
- Tampering with or hijacking of the on-disk `CloudManifest` cache file
- `CloudManager::boot()` key/hook/guard registration issues (e.g., stale state carried across requests in long-running runtimes) that apply the wrong `failClosed` or `disable` configuration to a booted key

Out of scope:
- Vulnerabilities in third-party LLM providers
- Denial-of-service via unlimited API spend (mitigated by `max_iterations` + char limits)
- Vulnerabilities in the separate phpClaw Cloud dashboard product: this policy covers the `phpclaw/phpclaw-cloud` OSS package only, not the paid dashboard
