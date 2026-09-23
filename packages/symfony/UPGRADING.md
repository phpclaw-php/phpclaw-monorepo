# Upgrading phpClaw for Symfony

## Update

```bash
composer update phpclaw/phpclaw-symfony --with-dependencies
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
```

Conversation, message and memory rows are preserved. Configuration stays in your `.env` and in
`config/packages/phpclaw.yaml`.

## Rolling back

Back up the database first, then:

```bash
composer require phpclaw/phpclaw-symfony:<old-version> --with-dependencies
php bin/console doctrine:migrations:migrate
php bin/console cache:clear
```

## Uninstalling

```bash
php bin/console doctrine:migrations:migrate prev
composer remove phpclaw/phpclaw-symfony
```

Reverting the migration drops `phpclaw_conversations`, `phpclaw_messages` and `phpclaw_memory`,
deleting every stored conversation, message and memory entry. Removing the package without reverting
leaves the tables in place. Delete `config/packages/phpclaw.yaml`, the bundle entry in
`config/bundles.php` and your `PHPCLAW_*` `.env` entries by hand.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
