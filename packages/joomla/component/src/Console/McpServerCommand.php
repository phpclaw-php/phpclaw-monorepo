<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Component\Administrator\Console;

use Closure;
use Joomla\Console\Command\AbstractCommand;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PhpClaw\Joomla\Component\Administrator\Engine\ToolBuilder;
use PhpClaw\Mcp\PhpClawMcpServer;
use PhpClaw\Mcp\Transport\StdioTransport;
use PhpClaw\Tools\ToolRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Joomla CLI command: php cli/joomla.php phpclaw:mcp-server
 */
final class McpServerCommand extends AbstractCommand
{
    private const EXIT_SUCCESS = 0;

    protected static $defaultName = 'phpclaw:mcp-server';

    /**
     * Create a new McpServerCommand instance.
     *
     * @param  (Closure(ToolRegistry): void)|null  $runner  Transport runner; null serves the registry over stdio.
     */
    public function __construct(
        private readonly ?Closure $runner = null,
    ) {
        parent::__construct();
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setDescription('Start the phpClaw MCP server (stdio transport).');

        parent::configure();
    }

    /**
     * Start the stdio MCP server, exposing every built tool that the configured deny list
     * does not remove.
     *
     * @param  InputInterface  $input  Console input.
     * @param  OutputInterface  $output  Console output (unused - stdout carries JSON-RPC).
     * @return int Console exit code (SUCCESS on clean exit).
     */
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $config = PhpClawConfig::fromRegistry(EngineFactory::getPluginParams());
        $registry = new ToolRegistry;
        $registry->register((new ToolBuilder)->build($config, applyProfile: false, allowPhpWrite: false), $config->toolDeny, ToolBuilder::TOOL_GROUPS);

        $runner = $this->runner ?? static function (ToolRegistry $registry): void {
            (new PhpClawMcpServer($registry))->serve(new StdioTransport);
        };

        $runner($registry);

        return self::EXIT_SUCCESS;
    }
}
