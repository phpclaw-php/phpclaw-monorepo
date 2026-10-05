# phpClaw

The AI agent engine for PHP. One library that talks to Anthropic, OpenAI, Groq, Gemini, Mistral,
DeepSeek, Ollama or any OpenAI-compatible endpoint, runs tools in a ReAct loop, screens prompts
through guards, remembers conversations, and powers every phpClaw adapter. Zero framework
dependencies, PHP 8.1 and later.

## 0.1.3 (2026-10-05)

### Added
- Add agent primitives: prompt templates, structured output, fallback, rate limit, response cache, token budget (#52)

### Fixed
- OpenAI tool format for deepseek and custom, stable provider order (#62)

## 0.1.2 (2026-09-29)

### Added
- Model-profile tool routing and skill context, core code standard (#5)

### Fixed
- Tool safety defaults, skills on demand, guard and shell hardening (#8)
- StoreMessages(false) stops memory writes in plain core (#50)

## 0.1.1 (2026-09-24)

### Fixed
- Tool routing on the user message, ShellTool disk keywords, docblock cleanup (#1)

## 0.1.0 (2026-09-20)

- Initial release.
