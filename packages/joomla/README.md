<p align="center">
    <img alt="phpclaw" src=".github/assets/phpclaw-logo-light.svg#gh-light-mode-only" height="120">
    <img alt="phpclaw" src=".github/assets/phpclaw-logo-dark.svg#gh-dark-mode-only" height="120">
</p>

<p align="center">
<a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-8.1%2B-blue" alt="PHP"></a>
<a href="https://www.joomla.org"><img src="https://img.shields.io/badge/Joomla-4%20%7C%205%20%7C%206-5091CD" alt="Joomla"></a>
<a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green" alt="License"></a>
<a href="https://github.com/phpclaw-php/phpclaw-monorepo/actions/workflows/joomla.yml"><img src="https://img.shields.io/github/actions/workflow/status/phpclaw-php/phpclaw-monorepo/joomla.yml?label=tests" alt="Tests"></a>
<a href="https://github.com/phpclaw-php/phpclaw-monorepo/releases"><img src="https://img.shields.io/github/v/release/phpclaw-php/phpclaw-monorepo?filter=joomla-v*&label=version" alt="Version"></a>
<a href="https://github.com/phpclaw-php/phpclaw-monorepo/releases"><img src="https://img.shields.io/endpoint?url=https://gist.githubusercontent.com/erakashpatel/9c6fad1842ba77bcecfe01aee22e521f/raw/downloads-joomla.json" alt="Downloads"></a>
</p>

<h1 align="center">Talk to your Joomla site.</h1>
<p align="center">phpClaw is the AI layer for Joomla.</p>

---

Running a site means digging through Articles, Users, and Extensions to answer questions that should take five seconds. phpClaw replaces the digging with a conversation, backed by Joomla-aware tools.

Ask about articles, categories, users, or your live database and get a real answer. No custom query, no admin-screen archaeology.

Same agent, three ways in: the admin chat, the CLI, or the component's administrator API.

## Just ask

```
> Show me unpublished articles older than 30 days
> Which extensions need updating?
> List users who registered this week
```

## Joomla Admin

<img alt="phpClaw chat panel in the Joomla admin" src=".github/assets/screenshots/chat.png" width="800">

A chat panel inside the Joomla back-end, reached from **Components → phpClaw**. Ask a question, the agent picks a tool, runs it against your site, and answers in the same panel.

## CLI Experience

<img alt="phpClaw Joomla CLI in action" src=".github/assets/screenshots/cli.png" width="800">

The same agent from your terminal, scriptable and CI-friendly:

```bash
php cli/joomla.php phpclaw "show unpublished articles"
php cli/joomla.php phpclaw "check extension updates" --stream
```

## Web Services API

<img alt="phpClaw Joomla API response" src=".github/assets/screenshots/rest.png" width="800">

Two POST routes on Joomla's Web Services API, published by `plg_webservices_phpclaw` and served by
the api application. Authenticate with a Joomla API token, no session and no CSRF token.

```
POST api/index.php/v1/phpclaw/chat            synchronous agent run
POST api/index.php/v1/phpclaw/chat/stream     SSE streaming chat
```

```bash
curl -X POST https://example.com/api/index.php/v1/phpclaw/chat \
  -H "Authorization: Bearer YOUR_JOOMLA_API_TOKEN" \
  -H "Accept: application/vnd.api+json" \
  -H "Content-Type: application/json" \
  -d '{"message":"how many articles were published today?"}'
```

The streaming route negotiates `text/event-stream`; any other `Accept` value is answered `406`.

Both routes require the `phpclaw.chat.use` permission and are scoped to the caller's own
conversations unless they hold `phpclaw.chat.manageall`.

Joomla grants API Login to Super Users only, and `plg_user_token` limits API tokens to Super Users
by default. To let Manager or Administrator call these routes, grant **API Login** to those groups
in Global Configuration and add them to **Allowed User Groups** in the User Joomla API Token plugin.

## Administrator endpoints

The admin screens use task-dispatched endpoints that need an authenticated admin session plus a
CSRF token: `task=api.send`, `task=api.stream` and `task=api.loadConversation` for chat, and
`task=api.saveSettings`, `task=api.enablePlugin` and `task=api.testConnection` for administrators.
Settings, plugin enabling and the connection probe are deliberately not on the Web Services API.

## Installation

1. Download the latest package ZIP from [Releases](https://github.com/phpclaw-php/phpclaw-monorepo/releases).
2. Upload it via **System → Install → Extensions → Upload Package File**.
3. Open **Components → phpClaw → Chat** to pick a provider and start. The package enables its own
   two plugins during install, so there is nothing to switch on first.

Super Users can call the Web Services API straight away. To open it to Manager or Administrator,
grant them **API Login** in **System → Global Configuration → Permissions** and add their group to
**Allowed User Groups** on the **User - Joomla API Token** plugin. Both are Joomla settings that
default to Super Users, and the Guide's REST tab restates them in the admin.

Full setup, configuration, and provider options are documented at [phpclaw.ai/docs/adapters/joomla](https://phpclaw.ai/docs/adapters/joomla).

## Key features

✅ **Natural language**: ask in plain English, no query syntax to learn

✅ **Joomla-native tools**: articles, users, categories, extensions, plus read-only database access

✅ **Admin chat**: inside the Joomla back-end, where your team already works

✅ **CLI**: the same agent, scriptable and CI-friendly, plus a `phpclaw:mcp-server` command

✅ **Administrator API**: task-dispatched endpoints for admin-side integrations, behind an authenticated session and CSRF token

✅ **Multiple AI providers**: Anthropic, OpenAI, Groq, Gemini, Mistral, DeepSeek, Ollama, and any custom OpenAI-compatible endpoint

✅ **Conversation memory**: context carries across a session

### How it fits together

```
Joomla → phpClaw package (plg_system_phpclaw + com_phpclaw) → phpClaw agent runtime → your configured AI provider
```

## Documentation

This README gets you installed. Everything else (configuration, providers, memory, skills, Cloud, the full tool reference, REST API, CLI, code examples, upgrading, troubleshooting) lives at:

**[phpclaw.ai/docs/adapters/joomla](https://phpclaw.ai/docs/adapters/joomla)**

## Contributing

Contributions are welcome. See the [contributing guide](https://phpclaw.ai/docs/contributing).

## Security

Please review our [security policy](https://github.com/phpclaw-php/phpclaw-monorepo/security/policy) for reporting vulnerabilities.

## License

MIT. See [LICENSE](LICENSE). Part of the [phpClaw](https://github.com/phpclaw-php/phpclaw-monorepo) project.

Joomla is a trademark of Open Source Matters, Inc. phpClaw is an independent open-source project and is not affiliated with or endorsed by Open Source Matters.
