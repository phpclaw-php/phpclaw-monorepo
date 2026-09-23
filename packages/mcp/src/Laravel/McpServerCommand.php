<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Laravel;

use Illuminate\Console\Command;
use PhpClaw\Mcp\Generic\CliRunner;
use PhpClaw\Tools\ToolRegistry;

/**
 * Artisan command: php artisan phpclaw:mcp-server
 */
final class McpServerCommand extends Command
{
    protected $signature = 'phpclaw:mcp-server {--transport=stdio : Transport mode: stdio or http}';

    protected $description = 'Start the phpClaw MCP server (stdio or HTTP transport)';

    /**
     * Start the MCP server.
     *
     * @param  ToolRegistry  $registry  Resolved from the service container.
     * @return int Laravel command exit code (SUCCESS on clean exit, FAILURE when HTTP was refused).
     */
    public function handle(ToolRegistry $registry): int
    {
        $served = CliRunner::serve(
            $registry,
            (string) ($this->option('transport') ?: 'stdio'),
            fn (string $message, bool $isError) => $isError ? $this->error($message) : $this->info($message),
        );

        return $served ? self::SUCCESS : self::FAILURE;
    }
}
