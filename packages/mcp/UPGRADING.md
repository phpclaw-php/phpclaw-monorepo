# Upgrading phpclaw/phpclaw-mcp

## Update

```bash
composer update phpclaw/phpclaw-mcp --with-dependencies
```

This package is the MCP server and owns no database tables and no configuration files, so
an update changes code only. It requires `phpclaw/phpclaw`; `--with-dependencies` keeps the two in
step.

Restart any long-running MCP server process after updating so clients pick up the new build. Client
configuration (Claude Desktop, Cursor, and others) points at your own command and is unaffected.

## Rolling back

```bash
composer require phpclaw/phpclaw-mcp:<old-version> --with-dependencies
```

## Removing

```bash
composer remove phpclaw/phpclaw-mcp
```

Nothing is left behind. Remove the phpClaw entry from your MCP client configuration as well.

## Support

- Documentation: [phpclaw.ai/docs](https://phpclaw.ai/docs)
- GitHub: [github.com/phpclaw-php/phpclaw-monorepo](https://github.com/phpclaw-php/phpclaw-monorepo)
