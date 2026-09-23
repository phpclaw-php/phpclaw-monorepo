# Security Policy

## Supported Versions

The latest release on the current line is supported.

## Reporting a Vulnerability

Please **do not** report security vulnerabilities via GitHub issues.

Email: **er.akashpatel1908@gmail.com**

Include in your report:
- A description of the vulnerability
- Steps to reproduce
- phpClaw MCP version, PHP version
- Any proof-of-concept code (if applicable)

We will acknowledge your report within **48 hours** and aim to release a fix within **14 days** for critical issues.

## Scope

In scope:
- Localhost origin enforcement bypass: both HTTP transports (`HttpTransport`, `StreamableHttpTransport`) reject any `REMOTE_ADDR` that is not `127.0.0.1` or `::1`; anything that allows a non-loopback address through
- Cross-origin browser request bypass: both HTTP transports reject any request carrying an `Origin` header; bypasses of this check
- Bearer-token auth bypass: `PHPCLAW_MCP_TOKEN` is required on **every** call (including `initialize`); the transports fail-closed when the token is empty; bypasses of `McpSecurity::validateToken()` (timing-safe `hash_equals`)
- Rate-limit bypass: `McpSecurity::rateLimit()` enforces 60 requests/minute per token hash via APCu (or in-process fallback); exploitation of the in-process fallback to exceed the shared limit
- Tool policy bypass: `McpSecurity::isToolAllowed()` enforces the `PHPCLAW_TOOL_ALLOW` / `PHPCLAW_TOOL_DENY` lists; ways to invoke a denied or non-allowed tool
- Prompt injection in tool call arguments: `GuardRegistry::scanToolArguments()` runs on all string leaves of tool arguments before execution, skipping the `PromptOnlyGuardInterface` guards (`code_injection`, `pii_detection`, `message_length`); bypasses that allow injected instructions to reach the LLM
- Prompt injection via `db-schema` prompt argument: the `table` argument is interpolated directly into LLM-facing instruction text with no additional sanitisation beyond the guard pipeline
- Adapter-private tools exposed over MCP without explicit opt-in via the allow/deny policy
- API key presence disclosure via the `phpclaw://config` built-in resource: key values are redacted to `***configured***` but which providers have keys configured is revealed; any regression that exposes actual key values

Out of scope:
- Vulnerabilities in third-party LLM providers (Anthropic, OpenAI, Groq, Gemini, Mistral, Ollama)
- Denial-of-service via unlimited API spend (mitigated by `max_iterations` and character limits in the core engine)
- Vulnerabilities in the PHP runtime, web server, or operating system
- Stdio transport authentication: the stdio transport operates over a trusted local process pipe and provides no auth layer by design; exposing stdio over the network is a host configuration error, not a phpClaw vulnerability
- Vulnerabilities in a third-party MCP server that a host application connects to (this package ships a server only; no MCP client is included)
