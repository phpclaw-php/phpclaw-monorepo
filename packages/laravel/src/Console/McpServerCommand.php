<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Console;

use Illuminate\Console\Command;
use PhpClaw\Mcp\Generic\CliRunner;
use PhpClaw\Tools\ToolRegistry;

/**
 * Artisan command `php artisan phpclaw:mcp-server`, starts the phpClaw MCP server over stdio or HTTP transport.
 */
final class McpServerCommand extends Command
{
    protected $signature = 'phpclaw:mcp-server {--transport=stdio : Transport mode (stdio or http)}';

    protected $description = 'Start the phpClaw MCP server (stdio or HTTP transport)';

    /**
     * Boot the MCP server with the registered tool set.
     *
     * @param  ToolRegistry  $registry  Resolved from the service container.
     * @return int Artisan exit code (SUCCESS on clean exit, FAILURE when HTTP was refused).
     */
    public function handle(ToolRegistry $registry): int
    {
        $served = CliRunner::serve(
            $registry,
            (string) ($this->option('transport') ?: 'stdio'),
            function (string $message, bool $isError): void {
                if ($isError) {
                    $this->error($message);
                } else {
                    $this->info($message);
                }
            },
        );

        return $served ? self::SUCCESS : self::FAILURE;
    }
}
