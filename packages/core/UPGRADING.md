# Upgrading phpclaw/phpclaw

## Update

```bash
composer update phpclaw/phpclaw
```

The engine owns no database tables and no configuration files, so an update changes code only. It
does write to a workspace directory when the file and shell tools are enabled; that directory is
yours and is never touched by an update.

When an adapter package (`phpclaw/phpclaw-wordpress`, `phpclaw/phpclaw-laravel`, and so on) pins a
core version, update the adapter instead and let Composer resolve core:

```bash
composer update phpclaw/phpclaw-<adapter> --with-dependencies
```

Every built-in tool returns one JSON envelope, `{"success", "data", "meta", "warnings"}`, and a
refusal uses the same shape with an `error` object. Code that read a tool result directly now reads
it from `data`. Adapters that hand the result straight to the model need no change.

The engine also asks the host one question before a tool runs, whether an authenticated caller is
present, through an optional `ToolAuthorizerInterface` passed to `ToolRegistry`. Supplying one is
optional: with none bound every caller is allowed, exactly as before. The human approval gate is
unaffected.

## Rolling back

```bash
composer require phpclaw/phpclaw:<old-version>
```

## Removing

```bash
composer remove phpclaw/phpclaw
```

Nothing is left behind. Any adapter package that depends on it must be removed first.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
