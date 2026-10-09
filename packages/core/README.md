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

✅ **Memory recall**: each message carries up to 3 stored memory entries that share words with it, labelled as reference material. `longTermMemory($topK)` changes the count and `0` turns recall off. A driver implementing `SearchableMemoryInterface` answers through its own `search()` instead of being scanned.

✅ **8 default guards + 1 opt-in**: prompt-injection, Unicode/homoglyph, role-switch, PII, code-injection, destructive-SQL and length checks scan every message before it reaches the provider, on by default, zero config.

✅ **50 lifecycle hooks**: observe or react to every step of the agent loop, `agent.before` through `skill.not_matched`, without touching core code.

✅ **Streaming, prompt caching, extended thinking**: token-by-token output, cached input tokens billed at Anthropic's discounted cache-read rate (see [Anthropic's pricing](https://www.anthropic.com/pricing)), Claude's reasoning chain exposed on `$response->thinking`.

✅ **MCP-ready**: wrap the same `ToolRegistry` and expose it to Claude Desktop, Claude Code, Cursor, or Windsurf via `phpclaw/phpclaw-mcp`, zero changes to your tools.

### How it fits together

```
Your message → GuardRegistry (blocks injection/PII before the LLM ever sees it)
             → HookRegistry (fires lifecycle events)
             → Agent (ReAct loop: provider + tools + memory + skills)
             → AgentResponse (text, tokens, tool calls, timing)
```

## Building blocks

Six opt-in primitives, all off by default. Leave them out and the agent behaves exactly as before.

**Prompt templates.** `{name}` placeholders, `{{` and `}}` for literal braces. A missing value, or one that is not a scalar or `Stringable`, throws `PromptTemplateException`. No escaping is done; the rendered text still passes the guard stack when you send it.

```php
use PhpClaw\Prompt\PromptTemplate;

$prompt = PromptTemplate::from('Extract the order details from: {body}')->format(['body' => $email]);
```

**Structured output.** `sendStructured()` returns data checked against your JSON Schema. It uses the vendor's native mode where phpClaw supports it (the `openai` preset, and the Anthropic models on its supported list), and otherwise asks the model to call one tool shaped like the schema. A reply that does not match is sent back for repair up to `maxParseRetries()` times (default 2), then `StructuredOutputException` is thrown. Supported keywords: `type`, `properties`, `required`, `items`, `enum`, `additionalProperties: false`, `minimum`, `maximum`, `minLength`, `maxLength`, `description`, `title`; any other keyword throws `UnsupportedSchemaException` before a call is made.

```php
$order = $claw->sendStructured($prompt, [
    'type' => 'object',
    'properties' => ['order_id' => ['type' => 'string'], 'total' => ['type' => 'number']],
    'required' => ['order_id', 'total'],
]);

$order->data['order_id'];
```

**Provider fallback.** `withFallback()` adds a provider tried when the one before it fails with a timeout, a connection error, a 429 or a 5xx. A 401, 403 or other 4xx does not fail over. Every provider in the chain must use the same tool format (for example `openai` and `groq`); mixing formats throws `AdapterException` at `build()`. Each fallback fires `provider.fallback`, and the response names the provider that answered.

**Outbound rate limit.** `rateLimit($requestsPerMinute, $maxWaitMs, $store)` spaces out calls to the provider with a token bucket. When the wait would pass `$maxWaitMs` the call throws `ProviderException` with status 429 instead of sleeping. Without `$store` the bucket lives in the built agent only; pass a PSR-16 store to share one bucket across every agent and process that uses it.

**Response cache.** `responseCache($psr16Cache, $ttl)` serves an identical request from any PSR-16 cache (`composer require psr/simple-cache` and a store). A hit costs no tokens and fires `provider.response_cached`. The key covers the providers, system prompt, token settings, messages and tools, so a changed history misses. Never share one store across tenants.

**Token budget.** `maxTokenBudget($tokens)` stops a run with `TokenBudgetExceededException` before the provider call that would take the spent input and output tokens over the budget, and fires `budget.exceeded`. The budget is checked between calls only, so one reply can overshoot it. Compaction summaries are not counted.

The stream fast path (a `stream()` call with no tools) is not budgeted, not cached and not retried. A streamed run with tools goes through the full loop and is.

```php
$claw = Claw::builder()
    ->provider('openai')->model('gpt-4o-mini')
    ->withFallback('groq', 'llama-3.1-8b-instant')
    ->rateLimit(60)
    ->responseCache($psr16)
    ->maxTokenBudget(50_000)
    ->build();
```

**Durable runs.** `durableRuns($stepBudget, $deadlineSeconds)` saves a `send()`, `stream()` or conversation run through the memory driver after every step, and stops it with `RunSuspendedException` when the process has spent its step or time budget. `withSuspendableApproval()` also pauses a run at a mutating tool until someone calls `approve()` or `deny()`; `resume($runId)` continues it in any process (a conversation run's finished turn is then added to its conversation), `pendingApprovals()` lists the paused runs and `cancel()` stops one. Both need a memory driver with `storeMessages` on, and are off by default.

```php
$claw = Claw::builder()->memory($memory)->withSuspendableApproval()->build();

try {
    $claw->send('Refund order 1042');
} catch (RunSuspendedException $e) {
    // later, in another request or worker:
    $claw->approve($e->runId, $claw->pendingApprovals()[0]->paused->callId());
    $claw->resume($e->runId);
}
```

To finish saved runs without tracking their ids, call `resumeDue()` from a cron job or worker. It resumes up to 5 runs that are due (suspended on a budget, paused with a decision recorded, or left running for 15 minutes by a process that died) and returns the counts. From cron, `vendor/bin/phpclaw-resume --bootstrap=claw.php` does the same, where `claw.php` returns your configured `Claw`:

```php
$report = $claw->resumeDue();
echo "{$report->completed} finished, {$report->suspended} paused again";
```

## Trust signals

2,686 tests, zero real network calls or API keys required to run the suite, every provider and tool call is mocked. 80% line coverage enforced in CI as one whole-package figure (covered statements over total statements), not per class. One public API (`Claw::send`/`stream`/`conversation`) that doesn't break without a major version bump.

## Documentation

This README gets you installed. Everything else, architecture, memory drivers, the full tool/guard/hook reference, skills, extending the engine, code examples, upgrading, troubleshooting, lives at:

**[phpclaw.ai/docs](https://phpclaw.ai/docs)**

## Contributing

Contributions are welcome. See the [contributing guide](https://phpclaw.ai/docs/contributing).

## Security

Please review our [security policy](https://github.com/phpclaw-php/phpclaw-monorepo/security/policy) for reporting vulnerabilities.

## License

MIT. See [LICENSE](LICENSE). Part of the [phpClaw](https://github.com/phpclaw-php/phpclaw-monorepo) project.
