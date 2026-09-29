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

## Console rights and Web API callers

- The console is a command-line run only: PHP's CLI in an area other than `adminhtml`,
  `webapi_rest`, `webapi_soap`, `graphql`, `frontend` and `crontab`. Cron, SOAP, GraphQL and
  storefront runs no longer skip the Magento tools' ACL check. An agent run from a cron job has no
  admin user, so the Magento tools (orders, products, `db_query`, logs and the rest) refuse there;
  the core utility tools are not ACL-checked.
- `/V1/phpclaw/send` and `/V1/phpclaw/chat/stream` answer `403` to any caller that is not an admin
  user, such as an integration token. Use an admin bearer token.
- `Model\Api\Send` and `Model\Api\Stream` take `IdentityResolver` as a new constructor
  argument. Production installs run `bin/magento setup:di:compile` after updating, as above.

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
