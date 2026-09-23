<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Symfony;

use PhpClaw\Mcp\Generic\CliRunner;
use PhpClaw\Tools\ToolRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command: bin/console phpclaw:mcp-server
 */
#[AsCommand(
    name: 'phpclaw:mcp-server',
    description: 'Start the phpClaw MCP server (stdio or HTTP transport)',
)]
final class McpServerCommand extends Command
{
    /**
     * Construct the command with the resolved tool registry.
     *
     * @param  ToolRegistry  $registry  The tool registry exposed via MCP methods.
     * @return void
     */
    public function __construct(
        private readonly ToolRegistry $registry,
    ) {
        parent::__construct();
    }

    /**
     * Configure command options.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption('transport', 't', InputOption::VALUE_OPTIONAL, 'Transport mode: stdio or http', 'stdio');
    }

    /**
     * Start the MCP server.
     *
     * @param  InputInterface  $input  The console input.
     * @param  OutputInterface  $output  The console output (unused on stdio; stdout carries JSON-RPC).
     * @return int Console exit code (SUCCESS on clean exit, FAILURE when HTTP was refused).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $served = CliRunner::serve(
            $this->registry,
            (string) ($input->getOption('transport') ?: 'stdio'),
            static fn (string $message, bool $isError) => $output->writeln(
                $isError ? "<error>{$message}</error>" : "<info>{$message}</info>",
            ),
        );

        return $served ? self::SUCCESS : self::FAILURE;
    }
}
