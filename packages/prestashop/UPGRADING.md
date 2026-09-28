# Upgrading phpClaw for PrestaShop

## Update

Update by re-uploading the module ZIP. Do **not** uninstall first: uninstalling drops your data.

1. Download the latest `phpclaw-prestashop-<version>.zip` from
   [Releases](https://github.com/phpclaw-php/phpclaw-monorepo/releases)
2. **Modules → Module Manager → Upload a module**, upload the new ZIP (it overwrites the existing
   module files)
3. Open **phpClaw → Settings** and confirm your provider, API key and model

Settings live in PrestaShop `Configuration` (the `ps_configuration` table), so they survive a file
overwrite. The Settings page also checks the update server and shows a notice when a newer version
is available; the check fails silently when the server is unreachable.

## The MCP server runs tools as non-interactive

`php modules/phpclaw/cli/phpclaw.php mcp-server` builds its tools as non-interactive, the same way the
Guide page does. Two things follow for an MCP client:

- `database` runs raw SQL only for a PrestaShop SuperAdmin employee. The MCP process has no employee
  session, so raw SQL over MCP is refused.
- A `file_write` tool that an extension adds through `actionPhpclawExtraTools` refuses `.php` files.

The interactive `send` command keeps the console behaviour: `database` runs raw SQL without the
SuperAdmin check, and an added `file_write` may write `.php` files, each write behind the
command-line approval prompt.

## Rolling back

Back up your database, then upload the older `phpclaw-prestashop-<version>.zip` the same way. Do not
uninstall first.

## Uninstalling

Uninstalling from **Module Manager → phpClaw → Uninstall** drops all four tables
(`{prefix}phpclaw_conversations`, `{prefix}phpclaw_messages`, `{prefix}phpclaw_memory` and
`{prefix}phpclaw_api_token`), deletes every `PHPCLAW_*` value from `Configuration`, and removes the
phpClaw admin tabs. Re-uploading the ZIP does not do this; only an explicit uninstall does.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
