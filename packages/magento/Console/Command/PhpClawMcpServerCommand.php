<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Console\Command;

use PhpClaw\Magento\Factory\PhpClawFactoryInterface;
use PhpClaw\Mcp\PhpClawMcpServer;
use PhpClaw\Mcp\Transport\StdioTransport;
use PhpClaw\Tools\ToolRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Magento CLI command: bin/magento phpclaw:mcp-server
 */
// non-final: Magento interceptor required
class PhpClawMcpServerCommand extends Command
{
    protected static $defaultName = 'phpclaw:mcp-server';

    protected static $defaultDescription = 'Start the phpClaw MCP server (stdio transport).';

    /**
     * Bind the factory that resolves the tool set this MCP server exposes.
     *
     * @param  PhpClawFactoryInterface  $phpClawFactory  Factory that resolves the configured tool set.
     * @param  string|null  $name  Optional command name override (Magento interceptor use).
     * @return void
     */
    public function __construct(
        private readonly PhpClawFactoryInterface $phpClawFactory,
        ?string $name = null,
    ) {
        parent::__construct($name ?? static::$defaultName);
    }

    /**
     * Start the stdio MCP server and block until stdin closes.
     *
     * @param  InputInterface  $input  Console input.
     * @param  OutputInterface  $output  Console output (unused; stdout carries JSON-RPC).
     * @return int Console exit code (SUCCESS on clean exit).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = new ToolRegistry;
        $registry->register($this->phpClawFactory->buildTools());

        $server = new PhpClawMcpServer($registry);
        $server->serve(new StdioTransport);

        return self::SUCCESS;
    }
}
