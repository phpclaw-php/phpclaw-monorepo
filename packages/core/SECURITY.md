# Security Policy

## Supported Versions

The latest release on the current line is supported.

## Reporting a Vulnerability

Please **do not** report security vulnerabilities via GitHub issues.

Email: **er.akashpatel1908@gmail.com**

Include in your report:
- A description of the vulnerability
- Steps to reproduce
- phpClaw core version, PHP version
- Any proof-of-concept code (if applicable)

We will acknowledge your report within **48 hours** and aim to release a fix within **14 days** for critical issues.

## Scope

In scope:
- Prompt injection patterns that bypass `InjectionGuard` and reach the AI provider
- Bypass paths for any of the 8 default guards (`CodeInjectionGuard`, `DestructiveSqlGuard`, `HomoglyphGuard`, `InjectionGuard`, `MessageLengthGuard`, `PiiDetectionGuard`, `RoleSwitchGuard`, `UnicodeGuard`)
- Path traversal escaping the configured workspace root via `FileReadTool` or `FileWriteTool`
- Execution of any command in `ShellTool`'s hard-blocked set (`rm`, `curl`, `sudo`, `bash`, `python`, and the full `HARD_BLOCKED` list) through any code path
- Raw message content persisted to the memory driver when `store_messages` is `false`, `PrivacyAwareMemory::set()` must be a strict no-op when disabled
- SSRF via `HttpTool` or a custom provider `base_url` input

Out of scope:
- Vulnerabilities in third-party AI providers (Anthropic, OpenAI, Gemini, etc.)
- Vulnerabilities in any PHP framework or CMS that hosts a phpClaw adapter
- Denial-of-service via unlimited API key spend (mitigated by `max_iterations` cap and `MessageLengthGuard` character limit)
- Hook listeners forwarding raw event payloads to external systems despite `store_messages` being disabled, core does not pre-filter hook payloads by design; this is listener responsibility
