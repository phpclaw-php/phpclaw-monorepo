# Upgrading phpclaw/phpclaw-cloud

## Update

```bash
composer update phpclaw/phpclaw-cloud --with-dependencies
```

This package is the cloud transport layer and owns no database tables and no configuration files, so
an update changes code only. Your cloud key and signing secret live in the host application's own
settings and are untouched.

It requires `phpclaw/phpclaw`; `--with-dependencies` keeps the two in step.

## Rolling back

```bash
composer require phpclaw/phpclaw-cloud:<old-version> --with-dependencies
```

## Removing

```bash
composer remove phpclaw/phpclaw-cloud
```

Nothing is left behind. With the package gone, no data is sent to phpClaw Cloud; clear the cloud key
from your settings as well.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
