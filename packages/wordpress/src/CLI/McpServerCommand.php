<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\CLI;

use PhpClaw\Mcp\PhpClawMcpServer;
use PhpClaw\Mcp\Transport\StdioTransport;
use PhpClaw\Tools\ToolRegistry;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Plugin;

/**
 * WP-CLI command: wp phpclaw mcp-server
 */
final class McpServerCommand
{
    /**
     * Start the phpClaw MCP server over stdio, exposing every registered tool the configured
     * deny list does not remove.
     *
     * @param  array<int, string>  $args  Positional arguments (unused).
     * @param  array<string, string>  $assocArgs  Named arguments (unused).
     * @return void
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $registry = new ToolRegistry;
        $registry->register(EngineFactory::registeredTools(
            Plugin::getInstance()->config(),
            (array) get_option('phpclaw_settings', []),
            Plugin::extraToolClasses(),
        ));

        $server = new PhpClawMcpServer($registry);
        $server->serve(new StdioTransport);
    }
}
