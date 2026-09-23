# Security Policy

## Supported Versions

The latest release on the current line is supported.

## Reporting a Vulnerability

Please **do not** report security vulnerabilities via GitHub issues.

Email: **er.akashpatel1908@gmail.com**

Include in your report:
- A description of the vulnerability
- Steps to reproduce
- OpenCart version, PHP version, extension version
- Any proof-of-concept code (if applicable)

We will acknowledge your report within **48 hours** and aim to release a fix within **14 days** for critical issues.

## Scope

In scope:
- SQL injection, XSS, CSRF vulnerabilities in extension controller or Twig template code
- Prompt injection bypasses that reach the AI provider
- Authentication / authorisation bypasses on admin controllers (both OC3 and OC4 code paths)
- Sensitive data leakage via admin AJAX endpoints (`/send`, `/stream`, `/load_conversation`, `/test_connection`, `/check_update`)
- Bypasses of the `db_query` tool's SELECT-only guard or its credential-column block list (`password`, `salt`, `secret`, `api_key`, etc.)
- SSRF via any server-side fetch surface: the Custom provider's Base URL, the Remote Skill URLs fetched when the engine is built, and the `http_request` core tool, which the agent can point at an arbitrary URL from a prompt
- Bypasses of the `shell_exec` tool's command allowlist or its hard-blocked command list
- Escapes from the file tools' workspace sandbox (`file_read`, `file_write`, `file_edit`, `code_search`)
- Anything that lets a web request define the `PHPCLAW_OC_CONSOLE` marker, which grants tool access without a module grant

Out of scope:
- Vulnerabilities in third-party AI providers (Anthropic, OpenAI, etc.)
- Vulnerabilities in OpenCart core
- The update-server URL (`PHPCLAW_UPDATE_SERVER`) is a server-side PHP constant; it is not editable through the admin UI and is therefore not a user-controlled SSRF surface
- Denial-of-service via unlimited API key spend (mitigated by max_iterations + char limits)
