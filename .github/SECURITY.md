# Security Policy

## Reporting a Vulnerability

**Do NOT open a public GitHub issue for security vulnerabilities.**

Email: **er.akashpatel1908@gmail.com**

Please include:
- Description of the vulnerability
- Steps to reproduce
- Affected package(s) and version(s)
- Potential impact

You will receive a response within 48 hours. Once the issue is confirmed, a fix will be released promptly and you will be credited in the CHANGELOG.

---

## Security Design

phpClaw is built with security as a first-class concern:

- **InjectionGuard**: scans every inbound message for prompt injection patterns before the agent loop runs. One of **8 default guards** registered via `GuardRegistry::registerDefaults()`. The full set is `CodeInjectionGuard`, `DestructiveSqlGuard`, `HomoglyphGuard`, `InjectionGuard`, `MessageLengthGuard`, `PiiDetectionGuard`, `RoleSwitchGuard`, `UnicodeGuard`. `RateLimitGuard` ships in core as a ninth guard and is opt-in. `ToolOutputGuard` is always on and sanitises tool results rather than prompts, so it is not part of the registry.
- **ShellTool**: allowlist-only command execution. The allowlist is constructor-supplied; the `HARD_BLOCKED` command set (`rm`, `curl`, `sudo`, `bash`, `python`, `kill`, …) is a `private const` and cannot be overridden by any caller. Hard-blocked commands fire a `shell.denied` hook and throw `ShellDeniedException`.
- **FileReadTool / FileWriteTool**: both workspace-sandboxed; both refuse paths that escape the configured workspace root, and **both** block framework-managed directories (`vendor/`, `node_modules/`, `.git/`, `.ssh/`, `.aws/`, `wp-admin/`, …). `FileWriteTool` additionally blocks the CMS-framework root directories (`core/`, `system/`, `sysext/`).
- **Hook contexts**: core hook payloads include the raw message and response text alongside metadata (`run_id`, `parent_run_id`, `provider`, `model`, token counts, `duration_ms`, and `conversation_id` where one is set). The privacy boundary is enforced at the **memory layer** via the `store_messages` setting and `PrivacyAwareMemory`. Hook listeners that forward events to external systems (custom webhook hooks, the optional `CloudWebhookHook` shipped via `phpclaw/phpclaw-cloud`) are responsible for honouring `store_messages` themselves, core does not pre-filter hook payloads.
- **MCP transport**: `StreamableHttpTransport` binds to `127.0.0.1` only and rejects any request whose `REMOTE_ADDR` is not `127.0.0.1` (never `0.0.0.0`). Bearer-token auth: set `PHPCLAW_MCP_TOKEN` and the server requires `Authorization: Bearer <token>` on every call, including `initialize`, the token is checked before the request body is parsed.
