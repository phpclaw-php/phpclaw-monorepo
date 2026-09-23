# Upgrading phpClaw for Drupal

## Update

```bash
composer update phpclaw/phpclaw-drupal --with-dependencies
drush updb -y
drush cr
```

Conversation, message and memory rows are preserved. Module configuration is Drupal-managed and
survives an update.

## Rolling back

Back up the database first, then:

```bash
composer require phpclaw/phpclaw-drupal:<old-version> --with-dependencies
drush updb -y
drush cr
```

## Uninstalling

`drush pmu phpclaw` removes all phpClaw data: Drupal's schema API drops `phpclaw_conversations`,
`phpclaw_messages` and `phpclaw_memory`, and `hook_uninstall()` deletes the agent queue and the
file-based memory directory. Disabling the module without uninstalling leaves tables and config in place.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
