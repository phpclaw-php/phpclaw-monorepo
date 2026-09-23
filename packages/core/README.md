<p align="center">
    <img alt="phpclaw" src=".github/assets/phpclaw-logo-light.svg#gh-light-mode-only" height="120">
    <img alt="phpclaw" src=".github/assets/phpclaw-logo-dark.svg#gh-dark-mode-only" height="120">
</p>

<p align="center">
<a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4" alt="PHP"></a>
<a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green" alt="License"></a>
<a href="https://github.com/phpclaw-php/phpclaw-monorepo/actions/workflows/core.yml"><img src="https://img.shields.io/github/actions/workflow/status/phpclaw-php/phpclaw-monorepo/core.yml?label=tests" alt="Tests"></a>
<a href="https://packagist.org/packages/phpclaw/phpclaw"><img src="https://img.shields.io/packagist/dt/phpclaw/phpclaw.svg" alt="Downloads"></a>
<a href="https://packagist.org/packages/phpclaw/phpclaw"><img src="https://img.shields.io/packagist/v/phpclaw/phpclaw" alt="Version"></a>
</p>

> **This is a read-only mirror**, auto-published from [`phpclaw-monorepo`](https://github.com/phpclaw-php/phpclaw-monorepo). Please open issues and pull requests there, not here.

<h1 align="center">The universal AI agent engine for PHP.</h1>
<p align="center">Zero framework dependencies. Any LLM provider. Runs anywhere PHP runs.</p>

---

> ### Editing this package inside the monorepo? Read this first.
>
> Every adapter consumes core as a **copied** composer path repository, not a symlink. Most composer
> commands will not refresh that copy, and none of them warn you.
>
> | Command | Delivers a core change? |
> |---|---|
> | `composer install` (no `vendor/` yet) | **yes** |
> | `composer install` (`vendor/` already present) | **NO** |
> | `composer update phpclaw/phpclaw` | **NO** |
> | `composer sync-core` | **yes** |
>
> **After editing `packages/core`, run `composer sync-core` in the adapter you are testing, or you
> are testing a stale copy.**
>
> The first `install` succeeding is what makes the second one dangerous: the command appears to
> work, so nobody suspects it later.

---

Most "AI in PHP" means gluing an HTTP client to a provider API and hand-rolling a tool-call loop, then finding out in production that nothing was checking user input for prompt injection. phpClaw is the loop and the guardrails, already built: give it tools, it runs a ReAct reasoning cycle until it has an answer, with memory, security, and lifecycle hooks wired in from the start, not bolted on later.

No framework required. No Guzzle, no PSR-18, no service container assumed. This is the same engine underneath every one of [phpClaw's 8 framework and CMS adapters](https://phpclaw.ai/docs/community/building-an-adapter), so what you learn here transfers directly if you later move to Laravel, WordPress, or any of the others.

## Quick start

```bash
composer require phpclaw/phpclaw
export ANTHROPIC_API_KEY="sk-ant-..."
```

```php
$agent = PhpClaw\Claw::builder()->build();
echo $agent->send("What can you help me with?")->text;
```

No provider argument, no config file. The builder checks `ANTHROPIC_API_KEY`, then `OPENAI_API_KEY`, `GROQ_API_KEY`, `GEMINI_API_KEY`, `MISTRAL_API_KEY`, `DEEPSEEK_API_KEY`, `OLLAMA_HOST`, in that order, and uses the first one it finds.

Give it tools and it starts doing real work against your real data:

```php
use PhpClaw\Claw;
use PhpClaw\Tools\ShellTool;
use PhpClaw\Tools\HttpTool;
use PhpClaw\Tools\DatabaseQueryTool;

$agent = Claw::builder()
    ->tools([
        new ShellTool(allowlist: ['ls', 'df', 'tail']),
        new HttpTool(),
        new DatabaseQueryTool($pdo),
    ])
    ->build();

$response = $agent->send('Check disk usage, tail the last 20 errors, summarise.');

echo $response->text;
print_r($response->toolsCalled);
```

## Just ask

```
> Check disk usage and tail the last 20 lines of the error log
> Is api.example.com responding right now?
> Run a read-only SELECT to count today's orders
```

## Why phpClaw

| | phpClaw | Generic LLM SDK |
|---|---|---|
| Tool-calling loop | ✅ ReAct, built in | ❌ you write it |
| Prompt injection defence | ✅ 8 default guards + 1 opt-in | ❌ |
| Memory abstraction | ✅ swappable drivers | ❌ |
| Streaming + tool-calls together | ✅ hybrid mode | ❌ |
| MCP server | ✅ via `phpclaw/phpclaw-mcp` | ❌ |
| Zero framework deps | ✅ `ext-curl`, `ext-json`, `ext-mbstring`, done | depends |
| Privacy-first storage | ✅ zero data sent in local mode | n/a |
| License | ✅ MIT, nothing gated | varies |

**Not trying to be everything.** phpClaw isn't built for complex RAG pipelines or multi-agent orchestration. One agent, one task, clean PHP integration.

## Key features

✅ **Framework-zero**: only `php ^8.1`, `ext-curl`, `ext-json`, `ext-mbstring` required. No Guzzle, no PSR-18.

✅ **8 AI providers**: Anthropic, OpenAI, Groq, Gemini, Mistral, DeepSeek, Ollama, and any custom OpenAI-compatible endpoint. Auto-detected from env, or set explicitly via `->provider()`.

✅ **ReAct loop**: tools, pluggable memory, security guards, and skills all run inside a single reasoning cycle, with a hard iteration cap so nothing runs away.

✅ **8 default guards + 1 opt-in**: prompt-injection, Unicode/homoglyph, role-switch, PII, code-injection, destructive-SQL and length checks scan every message before it reaches the provider, on by default, zero config.

✅ **40 lifecycle hooks**: observe or react to every step of the agent loop, `agent.before` through `skill.not_matched`, without touching core code.

✅ **Streaming, prompt caching, extended thinking**: token-by-token output, 90% cost reduction on cached tokens (Anthropic), Claude's reasoning chain exposed on `$response->thinking`.

✅ **MCP-ready**: wrap the same `ToolRegistry` and expose it to Claude Desktop, Claude Code, Cursor, or Windsurf via `phpclaw/phpclaw-mcp`, zero changes to your tools.

### How it fits together

```
Your message → GuardRegistry (blocks injection/PII before the LLM ever sees it)
             → HookRegistry (fires lifecycle events)
             → Agent (ReAct loop: provider + tools + memory + skills)
             → AgentResponse (text, tokens, tool calls, timing)
```

## Trust signals

2,054 tests, 5,665 assertions, zero real network calls or API keys required to run the suite, every provider and tool call is mocked. 80% line coverage enforced in CI as one whole-package figure (covered statements over total statements), not per class. One public API (`Claw::send`/`stream`/`conversation`) that doesn't break without a major version bump.

## Documentation

This README gets you installed. Everything else, architecture, memory drivers, the full tool/guard/hook reference, skills, extending the engine, code examples, upgrading, troubleshooting, lives at:

**[phpclaw.ai/docs](https://phpclaw.ai/docs)**

## Contributing

Contributions are welcome. See the [contributing guide](https://phpclaw.ai/docs/contributing).

## Security

Please review our [security policy](https://github.com/phpclaw-php/phpclaw-monorepo/security/policy) for reporting vulnerabilities.

## License

MIT. See [LICENSE](LICENSE). Part of the [phpClaw](https://github.com/phpclaw-php/phpclaw-monorepo) project.
