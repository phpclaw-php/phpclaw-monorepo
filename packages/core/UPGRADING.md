# Upgrading phpclaw/phpclaw

## Power tools are opt-in

`shell_exec`, `http_request`, `file_write` and `file_edit` are no longer among the tools
`ToolCatalogue::instantiateDefaults()` returns; that call now returns only `file_read`, `code_search`
and `project_info`. Code that builds its tool set from `instantiateDefaults()` (most adapters do)
needs to add these tools itself when it wants them, the same way it registers any custom tool:

```php
$tools = ToolCatalogue::instantiateDefaults($config);
$tools[] = new ShellTool(allowlist: ['ls', 'pwd', 'cat', 'grep']);
```

`ClawBuilder` never auto-registered these tools; `tools()` and `addTool()` were already the only way
to add them there, so no `ClawBuilder` call needs to change.

The default shell allowlist no longer includes `cat`, `head`, `tail` or `grep`. `file_read` already
reads workspace files without a shell, so most installs need no replacement. To keep a shell reader
available, pass an explicit allowlist that includes it, either directly:

```php
new ShellTool(allowlist: ['ls', 'pwd', 'cat', 'grep']);
```

or through `ClawBuilder::shellAllowlist()`, if the code that constructs your `ShellTool` reads the
allowlist back off `ToolConfig::$shellAllowlist`:

```php
$builder->shellAllowlist(['ls', 'pwd', 'cat', 'grep']);
```

`ShellTool` now matches the program name exactly and case-sensitively against the allowlist, on the
same token the process actually runs. A command like `LS` or `ls/../../bin/echo` is refused as
`not_in_allowlist` instead of being parsed down to a safe-looking prefix.

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
