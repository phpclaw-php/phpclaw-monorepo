# Contributing to phpClaw

Thank you for contributing. Every PHP framework community is welcome here.

---

## Ways to contribute

- **Fix a bug**: open an issue first, then submit a PR (for a wrong PHPDoc comment or a static-analysis warning, open a PR directly)
- **Build a community adapter**: see [phpclaw.ai/docs/community/building-an-adapter](https://phpclaw.ai/docs/community/building-an-adapter)
- **Improve docs**: `README.md` (each package's own README is a short pointer, not a manual) or the doc site at `phpclaw.ai/docs`
- **Write tests**: aim for 80%+ coverage; 95%+ on security-critical classes
- **Report security issues**: email er.akashpatel1908@gmail.com (do NOT open a public issue)

---

## Standards

Every rule below that can be checked automatically is enforced by the **Static Checks** job on your PR. The full list, with the reason for each rule and how to fix it, is at [phpclaw.ai/docs/code-standards](https://phpclaw.ai/docs/code-standards).

### PHP
- PHP 8.1 floor and language ceiling: every package requires `^8.1` and CI tests on 8.1. Use enums, `readonly` properties, pure intersection types, `never`. Do not use `readonly class` or standalone `null`/`false`/`true` types (8.2), typed class constants (8.3), or property hooks (8.4).
- `declare(strict_types=1)` in every file, no exceptions
- Code style enforced by Laravel Pint (`composer lint`)
- `final class` by default. Open for extension only via interfaces
- No static state except the six registries (ToolRegistry, GuardRegistry, HookRegistry, MemoryRegistry, ProviderRegistry, SkillRegistry)
- Return types always explicit, no implicit `mixed`

### Architecture
- **Zero framework dependencies in `packages/core`**. This is P1, it is never negotiable
- Depend on abstractions (`ToolInterface`, `MemoryInterface`, `ProviderInterface`), never on concrete implementations
- Constructor injection only, no service locators, no `app()` helpers in core

### Security (mandatory)
- ShellTool commands: allowlist only. Never add to HARD_BLOCKED without a PR discussion
- InjectionGuard: any new injection pattern must have a test in `InjectionGuardTest`
- FileReadTool / FileWriteTool: workspace sandbox must never be weakened
- `store_messages = true` default in every new adapter (matches core + CMS adapter behaviour). The opt-out path (`->storeMessages(false)` on `Claw::builder()`) must always work and must drop message content from persistence + cloud forwarding

---

## PR process

1. Fork the repo
2. Create a branch: `git checkout -b feat/my-feature`
3. Write code + tests
4. Run `composer test`. All tests must pass
5. Run `composer lint`. Code style must pass
6. Open a PR with a clear description of **what** and **why**

### For bug fixes
1. Fix the file where the bug was found
2. **Grep the whole project for the same pattern**. The same bug almost always exists in sibling files (other providers, other adapters)
3. Fix every file that matches
4. Your PR description must list all files changed

### For new adapters
- Follow the [Framework Adapter Guide](https://phpclaw.ai/docs/community/framework-adapter-guide) or [CMS Adapter Guide](https://phpclaw.ai/docs/community/cms-adapter-guide) exactly
- 80%+ test coverage before submitting
- Include an `examples/` folder with a working `example.php`
- No real HTTP in unit tests. Always mock `RawHttpClient`

---

## Testing requirements

| Rule | Detail |
|---|---|
| Minimum coverage | 80%, computed as one whole-package percentage (covered/total statements), not per class |
| Security classes | 95%+ (`GuardRegistry`, `InjectionGuard`, `ShellTool`, `FileReadTool`, `FileWriteTool`) |
| No real HTTP | Always mock `RawHttpClient` in unit tests |
| No real DB | Use `ArrayMemory` in unit tests |
| Feature tests | Mark skipped if no real API key: `if (! getenv('ANTHROPIC_API_KEY')) { self::markTestSkipped('Requires ANTHROPIC_API_KEY.'); }` |
| Test naming | `test_it_does_x_when_y()`, descriptive and readable |
| Isolation | Call `GuardRegistry::reset()` and `HookRegistry::reset()` in `setUp()` and `tearDown()` |

---

## Vendor copy (Windows / path repositories)

If you are testing against a Laravel or other framework app that installed phpClaw via a Composer path repository, symlinks may not work on Windows. After any change to core files, copy them:

```bash
cp packages/core/src/Agent/Agent.php vendor/phpclaw/phpclaw/src/Agent/Agent.php
```

Chain multiple copies with `&&`:

```bash
cp packages/core/src/Tools/ShellTool.php vendor/phpclaw/phpclaw/src/Tools/ShellTool.php && \
cp packages/core/src/Guards/GuardRegistry.php vendor/phpclaw/phpclaw/src/Guards/GuardRegistry.php
```

---

## Commit style

```
feat: add DatabaseQueryTool with SELECT-only enforcement
fix: guard bypass when user guards registered before defaults
docs: update ADAPTER_GUIDE with correct MemoryInterface signatures
test: add injection tests for unicode homoglyph bypass
```

For release versioning and the `release:major` / `release:minor` / `release:patch` PR labels, see the [contributing guide](https://phpclaw.ai/docs/contributing).

---

## Author

er.akashpatel1908@gmail.com
