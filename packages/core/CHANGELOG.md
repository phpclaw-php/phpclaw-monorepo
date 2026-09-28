# phpClaw

The AI agent engine for PHP. One library that talks to Anthropic, OpenAI, Groq, Gemini, Mistral,
DeepSeek, Ollama or any OpenAI-compatible endpoint, runs tools in a ReAct loop, screens prompts
through guards, remembers conversations, and powers every phpClaw adapter. Zero framework
dependencies, PHP 8.1 and later.

## 0.1.2 (2026-09-28)

### Added
- Model-profile tool routing and skill context, core code standard (#5)

### Fixed
- Tool safety defaults, skills on demand, guard and shell hardening (#8)

## 0.1.1 (2026-09-24)

### Fixed
- Tool routing on the user message, ShellTool disk keywords, docblock cleanup (#1)

## 0.1.0 (2026-09-20)

- Initial release.
