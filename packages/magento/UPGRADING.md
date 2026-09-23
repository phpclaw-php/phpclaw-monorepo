# Upgrading phpClaw for Magento 2 / Adobe Commerce

## Update

```bash
composer update phpclaw/phpclaw-magento --with-dependencies
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode only
bin/magento cache:flush
```

If you deploy by copying files rather than by Composer, replace the module directory contents first,
then run the same commands. Conversation, message and memory rows are preserved, and your settings
stay in `core_config_data`.

## Rolling back

Back up the database first, then:

```bash
composer require phpclaw/phpclaw-magento:<old-version> --with-dependencies
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode only
bin/magento cache:flush
```

Declarative schema is additive: rolling back to a release whose `db_schema.xml` omits a column will
drop that column on `setup:upgrade`.

## Uninstalling

```bash
bin/magento module:uninstall PhpClaw_Magento --remove-data
```

This drops `phpclaw_conversations`, `phpclaw_messages` and `phpclaw_memory`, and deletes every
`core_config_data` row matching `phpclaw/%`. A plain `bin/magento module:disable PhpClaw_Magento`
leaves tables and settings in place.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
