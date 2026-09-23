# Upgrading phpClaw for WordPress

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
