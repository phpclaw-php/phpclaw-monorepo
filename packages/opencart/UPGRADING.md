# Upgrading phpClaw for OpenCart

Applies to OpenCart 3 and OpenCart 4.

## Update

Update by re-uploading the extension ZIP. Do **not** uninstall first: uninstalling drops your data.

1. Download the latest ZIP for your OpenCart version from
   [Releases](https://github.com/phpclaw-php/phpclaw-monorepo/releases)
2. **Extensions → Installer**, upload the new ZIP (it overwrites the existing files)
3. **phpClaw → Settings**, confirm your provider, API key, and model

Settings live in OpenCart's own `{DB_PREFIX}setting` store and the three phpClaw tables are created
with `CREATE TABLE IF NOT EXISTS`, so both survive a ZIP overwrite.

When you open Settings, the module checks the update server and shows a banner if a newer version is
available. The check fails silently when the server is unreachable.

## Rolling back

Back up your database, then upload the older ZIP the same way. Do not uninstall first.

## Uninstalling

Uninstalling from **Extensions → Extensions → Modules → phpClaw** drops all three tables
(`{DB_PREFIX}phpclaw_conversations`, `{DB_PREFIX}phpclaw_messages`, `{DB_PREFIX}phpclaw_memory`) and
removes the saved settings. Every stored conversation, message, and memory entry is deleted.
Re-uploading the ZIP does not do this; only an explicit uninstall does.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
