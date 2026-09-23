=== phpClaw AI Agent ===
Contributors: akashpatel
Tags: ai, agent, chatbot, llm, woocommerce
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

The AI assistant that knows your entire WordPress site. Ask questions in plain English - get real answers from real data.

== Description ==

phpClaw is an AI assistant that lives inside your WordPress admin. Connect any AI provider and ask questions about your site in plain English.

No SQL. No dashboards. No prompts to memorize. Just ask:

* "How many published posts do I have?"
* "Which plugins are active and outdated?"
* "Show me low-stock WooCommerce products"
* "Any pending comments to moderate?"
* "Revenue this week?"

**30 built-in tools** covering posts, users, plugins, menus, media, comments, taxonomies, cron, options, database, logs, HTTP, file read/write/edit, shell, code search, project info, plugin ZIP builder, and 10 WooCommerce tools (orders, products, customers, reports, stock, coupons, categories, reviews, shipping, tax).

**8 AI providers** - Anthropic Claude, OpenAI GPT, Groq, Google Gemini, Mistral, DeepSeek, Ollama (free, local), or Custom (any OpenAI-compatible endpoint). Switch providers from Settings - zero code changes.

= Features =

* **Chat UI** - Chat-style conversation interface built into wp-admin with conversation history
* **Live tool streaming** - Server-Sent Events stream `tool_before` / `tool_after` / `chunk` / `done` frames so the UI shows tool calls and provider tokens the moment they happen
* **Typed tool cards** - posts, users, plugins, taxonomies, comments, options, menus, media, cron, and SQL results render as native lists / tables with edit-links and badges (raw JSON one click away)
* **WP-CLI** - `wp phpclaw send`, `wp phpclaw mcp-server`
* **REST API** - `POST /wp-json/phpclaw/send` for any frontend, mobile app, or external integration
* **WooCommerce Native** - Automatically detects WooCommerce and registers 10 commerce tools. HPOS compatible.
* **Security Built-in** - Multi-layered protection against prompt injection, unsafe commands, and data leakage
* **Privacy First** - Message content is stored by default and can be disabled by unchecking Store Messages in Settings

= Supported AI Providers =

* Anthropic Claude (claude-haiku, claude-sonnet, claude-opus)
* OpenAI GPT (gpt-4o, gpt-4o-mini)
* Groq (llama, mixtral - fast and free tier available)
* Google Gemini
* Mistral
* DeepSeek
* Ollama - run any model locally, completely free, no API key needed
* Custom - any OpenAI-compatible endpoint (OpenRouter, Together, remote Ollama; set a Base URL in Settings)

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via **Plugins > Add New > Upload Plugin**
2. Activate the plugin through the **Plugins** menu
3. Go to **phpClaw > Settings** and select your AI provider
4. Enter your API key (or choose Ollama for local models - no key needed)
5. Go to **phpClaw > Chat** and start asking questions

== External services ==

phpClaw connects to the external services below. Apart from the update check, nothing is sent until you configure the service in Settings.

= AI provider =

When you send a chat message, phpClaw sends the message, the earlier messages in the conversation and the results of any tools it ran to the AI provider selected in Settings: Anthropic (api.anthropic.com), OpenAI (api.openai.com), Groq (api.groq.com), Google Gemini (generativelanguage.googleapis.com), Mistral (api.mistral.ai), DeepSeek (api.deepseek.com), or the Base URL you set for Custom. Ollama runs on a server you choose. Each provider handles this data under its own terms of service and privacy policy, which you accept when you create your API key with that provider.

= phpClaw Cloud (optional) =

Only when you enter a Cloud Key in Settings and Store Messages is enabled, phpClaw sends run traces to phpClaw Cloud (phpclaw.ai). A trace contains the message and response text, tool names, token counts and timings. Cloud scan features also send the message text for scanning. Privacy policy: https://phpclaw.ai/platform/privacy

= Update check =

When WordPress checks for plugin updates, phpClaw requests https://phpclaw.ai/api/wp-update to see whether a newer version exists. The request carries the installed phpClaw version and the standard WordPress request headers, which include your site URL. No chat content is sent.

== Frequently Asked Questions ==

= Which AI provider should I use? =

If you want the best results, use Anthropic Claude or OpenAI GPT. If you want free unlimited usage, install Ollama locally and pull any model - no API key needed.

= Does this store my data? =

By default, message content is stored in your WordPress database. Disable this by unchecking **Store Messages** in Settings. Conversation timestamps are always recorded, even with Store Messages disabled.

= Does it work without WooCommerce? =

Yes. The 20 WordPress tools (11 WP-native + 7 core utility + 2 plugin-builder) work without WooCommerce. The 10 commerce tools (orders, products, stock, etc.) auto-register when WooCommerce is detected.

= Is it safe? =

Yes. The plugin has multi-layered security: prompt injection scanning on every message, read-only database access (no writes), command allowlist, character limits, and CSRF protection on all endpoints.

= Can I use it via the REST API? =

Yes. `POST /wp-json/phpclaw/send` accepts a `message` and optional `conversation_id`. Authenticate with a WordPress Application Password, or with a logged-in cookie plus an `X-WP-Nonce` header. Either way the caller needs the `phpclaw_use_chat` capability.

= What is the WP-CLI command? =

`wp phpclaw send "your question here"` - supports `--provider` and `--model` overrides and `--stream` for token-by-token output.

= Does it support multisite? =

Not tested on multisite yet. Single-site support is fully stable.

== Screenshots ==

1. Chat UI - ask questions in plain English directly from wp-admin and get answers from real site data.
2. Settings page - choose AI provider, enter API key, and Test Connection in seconds.
3. About page - feature overview showing all built-in tools (20, or 30 with WooCommerce active) and supported providers.
4. Guide page - 10-tab reference: Quickstart, Tools, Providers, Memory, Guards, Hooks, Skills, REST API, WP-CLI, and Privacy.
5. Analytics - usage stats showing conversations, messages, and active activity.
6. Site Health integration - phpClaw reports its configuration status in WordPress Site Health.

== Changelog ==

= 1.0.0 =
* Initial release - chat UI, 30 tools (11 WP-native + 7 core utility + 2 plugin-builder + 10 WooCommerce), 8 AI providers, WP-CLI, REST API, WooCommerce support

== Upgrade Notice ==

= 1.0.0 =
Initial release.
