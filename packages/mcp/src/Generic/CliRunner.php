<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Generic;

use PhpClaw\Mcp\PhpClawMcpServer;
use PhpClaw\Mcp\Transport\StdioTransport;
use PhpClaw\Mcp\Transport\StreamableHttpTransport;
use PhpClaw\Tools\ToolRegistry;

/**
 * Bootstrap an MCP server for plain PHP projects (no framework).
 */
final class CliRunner
{
    private const TRANSPORT_STDIO = 'stdio';

    private const TRANSPORT_HTTP = 'http';

    private const ENV_TOKEN = 'PHPCLAW_MCP_TOKEN';

    /**
     * Start a stdio MCP server and block until stdin closes.
     *
     * @param  ToolRegistry  $registry  Pre-populated tool registry.
     * @return void
     */
    public static function run(ToolRegistry $registry): void
    {
        self::serve($registry);
    }

    /**
     * Start the MCP server over stdio, or HTTP when $transport is 'http'.
     *
     * @param  ToolRegistry  $registry  Pre-populated tool registry.
     * @param  string  $transport  'stdio' (default) or 'http'.
     * @param  callable|null  $emit  fn(string $message, bool $isError): void, defaults to STDOUT/STDERR.
     * @return bool True when the server served; false when HTTP was refused (missing token).
     */
    public static function serve(
        ToolRegistry $registry,
        string $transport = self::TRANSPORT_STDIO,
        ?callable $emit = null,
    ): bool {
        $emit ??= static fn (string $message, bool $isError): int => fwrite($isError ? STDERR : STDOUT, $message."\n");

        $server = new PhpClawMcpServer($registry);

        if ($transport === self::TRANSPORT_HTTP) {
            $token = (string) (getenv(self::ENV_TOKEN) ?: '');

            if ($token === '') {
                $emit('Refusing to start HTTP transport: set PHPCLAW_MCP_TOKEN to a non-empty value.', true);

                return false;
            }

            $emit('phpClaw MCP server started (HTTP transport).', false);
            $server->serve(new StreamableHttpTransport($token));

            return true;
        }

        $server->serve(new StdioTransport);

        return true;
    }
}
