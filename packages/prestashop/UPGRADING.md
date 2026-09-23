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
