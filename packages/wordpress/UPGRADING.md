# Upgrading phpClaw for WordPress

## Power tools are opt-in

`shell_exec`, `http_request`, `file_write` and `file_edit` are no longer offered on chat, WP-CLI or
the MCP server by default; only `file_read`, `code_search` and `project_info` are. If your site
relies on one of the four, add it back with the `phpclaw_extra_tools` filter, in your theme's
`functions.php` or a small must-use plugin:

```php
add_filter('phpclaw_extra_tools', function (array $classes): array {
    $classes[] = \PhpClaw\Tools\ShellTool::class;

    return $classes;
});
```

`ShellTool`, `FileWriteTool` and `FileEditTool` added this way are built with this adapter's own
configured values: a shell tool gets the configured `shell_allowlist`, and a file tool gets the
configured `workspace_root`. Any other class is built with its own constructor defaults. On the web
chat the approval gate still refuses a file write regardless of this filter; only WP-CLI can complete
a `.php`/`.phtml`/`.phar` write.

## Update

**Automatic.** phpClaw ships a self-hosted updater, so updates appear in wp-admin like any
wordpress.org plugin. When a newer version is available a notice appears on **Plugins** and under
**Dashboard → Updates**; click **Update Now**. The check is cached for 12 hours. Setting the
`update_server` config key to an empty string disables it.

**Manual.** Download the ZIP from [phpclaw.ai](https://phpclaw.ai), go to **Plugins → Add New →
Upload Plugin**, upload it and choose **Replace current with uploaded**.

Either way, open **phpClaw → Settings** afterwards and confirm your provider, API key and model.
Settings are stored as WordPress options and survive an update.

## Rolling back

Back up your database, then **deactivate** the plugin (do not delete), upload the older ZIP the same
way, and reactivate. Deactivating leaves your tables and options intact.

## Uninstalling

Data is removed only when you **delete** the plugin from **Plugins**, never on deactivate or update.
Deleting runs `uninstall.php`, which drops `{prefix}phpclaw_conversations`, `{prefix}phpclaw_messages`
and `{prefix}phpclaw_memory`, and deletes every option matching `phpclaw\_%`. To keep your data,
deactivate instead of deleting.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
