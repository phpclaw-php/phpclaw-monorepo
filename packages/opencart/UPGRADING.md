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

## Power tools are opt-in

`shell_exec`, `http_request`, `file_write` and `file_edit` are no longer offered by default; only
`file_read`, `code_search` and `project_info` are. To add one back, an extension listening on the
`phpclaw/extra/tools` event adds its class name to the event's list. `ShellTool` added that way gets
the configured shell allowlist, and `FileWriteTool` and `FileEditTool` get the configured workspace
root.

## The MCP server runs tools as non-interactive

The CLI script's `mcp-server` command builds its tools as non-interactive, the same way the Guide
page does. Two things follow for an MCP client:

- `db_query` runs raw SQL only for a caller holding the raw-SQL grant. The MCP server does not grant
  it, so raw SQL over MCP is refused.
- A `file_write` tool that an extension adds through `phpclaw/extra/tools` by class name refuses
  `.php` files.

The interactive `send` command keeps both: it runs raw SQL, and an added `file_write` may write
`.php` files, each write behind the command-line approval prompt.

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
