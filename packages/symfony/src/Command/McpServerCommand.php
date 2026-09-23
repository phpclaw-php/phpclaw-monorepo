<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Command;

use PhpClaw\Mcp\Generic\CliRunner;
use PhpClaw\Tools\ToolRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console command that starts the phpClaw MCP server over stdio or HTTP transport.
 */
#[AsCommand(
    name: 'phpclaw:mcp-server',
    description: 'Start the phpClaw MCP server (stdio or HTTP transport).',
)]
final class McpServerCommand extends Command
{
    /**
     * Construct the command with the resolved tool registry.
     *
     * @param  ToolRegistry  $registry  The tool registry exposed via MCP methods.
     * @param  \Closure|null  $runner  Transport runner; null defaults to CliRunner::serve().
     * @return void
     */
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ?\Closure $runner = null,
    ) {
        parent::__construct();
    }

    /**
     * Configure the --transport option.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption(
            name: 'transport',
            shortcut: 't',
            mode: InputOption::VALUE_OPTIONAL,
            description: 'Transport mode: stdio (default) or http.',
            default: 'stdio',
        );
    }

    /**
     * Start the MCP server over the selected transport and return the exit code.
     *
     * @param  InputInterface  $input  The console input.
     * @param  OutputInterface  $output  The console output (unused on stdio; stdout carries JSON-RPC).
     * @return int Console exit code (SUCCESS on clean exit, FAILURE when HTTP transport is refused).
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $transport = (string) ($input->getOption('transport') ?: 'stdio');

        $runner = $this->runner ?? CliRunner::serve(...);

        $served = $runner(
            $this->registry,
            $transport,
            static fn (string $message, bool $isError) => $output->writeln(
                $isError ? "<error>{$message}</error>" : "<info>{$message}</info>",
            ),
        );

        return $served ? self::SUCCESS : self::FAILURE;
    }
}
