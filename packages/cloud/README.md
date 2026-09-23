<p align="center">
    <img alt="phpclaw" src=".github/assets/phpclaw-logo-light.svg#gh-light-mode-only" height="120">
    <img alt="phpclaw" src=".github/assets/phpclaw-logo-dark.svg#gh-dark-mode-only" height="120">
</p>

<p align="center">
<a href="https://www.php.net"><img src="https://img.shields.io/badge/PHP-8.1%2B-blue" alt="PHP"></a>
<a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green" alt="License"></a>
<a href="https://github.com/phpclaw-php/phpclaw-monorepo/actions/workflows/cloud.yml"><img src="https://img.shields.io/github/actions/workflow/status/phpclaw-php/phpclaw-monorepo/cloud.yml?label=tests" alt="Tests"></a>
<a href="https://packagist.org/packages/phpclaw/phpclaw-cloud"><img src="https://img.shields.io/packagist/dt/phpclaw/phpclaw-cloud" alt="Downloads"></a>
<a href="https://packagist.org/packages/phpclaw/phpclaw-cloud"><img src="https://img.shields.io/packagist/v/phpclaw/phpclaw-cloud" alt="Version"></a>
</p>

> **This is a read-only mirror**, auto-published from [`phpclaw-monorepo`](https://github.com/phpclaw-php/phpclaw-monorepo). Please open issues and pull requests there, not here.

<h1 align="center">Optional cloud transport for phpClaw.</h1>
<p align="center">One key, zero code changes: tracing, monitoring, and usage limits for your agents.</p>

---

phpClaw runs entirely local by default. Add this package and one cloud key, and lifecycle events start forwarding to phpClaw Cloud: run tracing, per-model and per-tool monitoring, and token-usage limits, gated by your plan, no other code changes required.

## Quick start

```bash
composer require phpclaw/phpclaw-cloud
```

phpClaw discovers the package automatically. Set a key on the builder to activate it; leave it unset and the package does nothing:

```php
$claw = PhpClaw\Claw::builder()
    ->cloudKey('phpclaw_cloud_key_...')
    ->build();
```

## Why add Cloud?

Without this package, phpClaw agents run and disappear: no run history, no per-model or per-tool breakdown, no token-usage limits, nothing to look at after the fact. Add a cloud key and every lifecycle event forwards to phpClaw Cloud, giving you tracing and monitoring on the dashboard for your plan.

Everything stays local until you add a key. No cloud key, no network call, ever.

## Key features

✅ **One-line activation**: `->cloudKey()` on the `Claw` builder, nothing else to wire up

✅ **Run tracing**: every lifecycle event forwards to phpClaw Cloud for a full run history

✅ **Model-wise and tool-wise monitoring**: see usage broken down by provider, model, and tool

✅ **Token-usage limits**: track and cap usage against your plan's limits

✅ **Per-feature opt-out**: `->cloudDisable([...])` turns off individual cloud features while keeping the rest active

✅ **Keep content on your server**: add `hide_inputs`, `hide_outputs` or `hide_metadata` to `->cloudDisable([...])` and those fields reach phpClaw Cloud as `[hidden]`. Timings, token counts, status and tool names still arrive, so tracing and cost tracking keep working. `hide_inputs` also skips the cloud prompt scan, which would otherwise send the message. Names must be spelled exactly: like every `cloudDisable` name, a misspelled one is ignored

✅ **Secret-aware payloads**: `CloudPayloadBuilder` redacts secret-shaped fields, truncates strings over 8KB, replaces arrays longer than 100 entries with a marker, and caps the encoded payload at 64KB before anything leaves the process. Note that prompt and reply content is forwarded whenever a cloud key is set, unless `hide_inputs` / `hide_outputs` is in `cloudDisable`: core's hook dispatcher does not filter payloads by `store_messages`. The framework and CMS adapters gate cloud boot on their own Store Messages setting; a direct `Claw::builder()->cloudKey(...)` call has no such gate

✅ **Fire-and-forget**: designed so a cloud-side failure doesn't take down the host request

### How it fits together

```
Claw::builder()->cloudKey(...) → CloudManager::boot()
  → CloudWebhookHook registered → lifecycle events forwarded → phpClaw Cloud /v1/runs (tracing, monitoring, usage limits: by plan)
```

## Documentation

This README gets you installed. Everything else (the full event catalogue, self-hosting notes, code examples) lives at:

**[phpclaw.ai/docs/cloud](https://phpclaw.ai/docs/cloud)**

## Contributing

Contributions are welcome. See the [contributing guide](https://phpclaw.ai/docs/contributing).

## Security

Please review our [security policy](https://github.com/phpclaw-php/phpclaw-monorepo/security/policy) for reporting vulnerabilities.

## License

MIT. See [LICENSE](LICENSE). Part of the [phpClaw](https://github.com/phpclaw-php/phpclaw-monorepo) project.
