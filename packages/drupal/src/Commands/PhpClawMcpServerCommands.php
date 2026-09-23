<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Commands;

use Drush\Attributes as CLI;
use PhpClaw\Drupal\Commands\Base\PhpClawDrushCommands;
use PhpClaw\Drupal\PhpClawServiceFactory;
use PhpClaw\Drupal\Service\DrupalAgentContext;
use PhpClaw\Mcp\PhpClawMcpServer;
use PhpClaw\Mcp\Transport\StdioTransport;

/**
 * Drush command that starts the phpClaw MCP server over stdio.
 */
final class PhpClawMcpServerCommands extends PhpClawDrushCommands
{
    /**
     * Construct the command with the Drupal agent context used to resolve tools.
     *
     * @param  DrupalAgentContext  $context  All Drupal dependencies bundled as a context object.
     * @return void
     */
    public function __construct(
        private readonly DrupalAgentContext $context,
    ) {
        parent::__construct();
    }

    /**
     * Start the phpClaw MCP server (stdio transport).
     *
     * @return void
     */
    #[CLI\Command(name: 'phpclaw:mcp-server', aliases: ['phpclaw-mcp'])]
    #[CLI\Usage(name: 'drush phpclaw:mcp-server', description: 'Start the stdio MCP server')]
    public function mcpServer(): void
    {
        $registry = PhpClawServiceFactory::buildToolRegistry($this->context);

        $server = new PhpClawMcpServer($registry);
        $server->serve(new StdioTransport);
    }
}
