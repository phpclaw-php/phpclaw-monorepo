# Upgrading phpClaw for Laravel

## Update

```bash
composer update phpclaw/phpclaw-laravel --with-dependencies
php artisan migrate
```

Conversation, message and memory rows are preserved. Configuration stays in your `.env` and in
`config/phpclaw.php` if you published it.

If a release changes the published config or routes, re-publish them and merge your own edits back:

```bash
php artisan vendor:publish --tag=phpclaw-config --force
php artisan vendor:publish --tag=phpclaw-routes --force
```

## Rolling back

Back up the database first, then:

```bash
composer require phpclaw/phpclaw-laravel:<old-version> --with-dependencies
php artisan migrate
```

## Uninstalling

```bash
php artisan migrate:rollback
composer remove phpclaw/phpclaw-laravel
```

Rolling back the migrations drops `phpclaw_conversations`, `phpclaw_messages` and `phpclaw_memory`,
deleting every stored conversation, message and memory entry. Removing the package without rolling
back leaves the tables in place. Delete `config/phpclaw.php` and your `PHPCLAW_*` `.env` entries by
hand if you published them.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
