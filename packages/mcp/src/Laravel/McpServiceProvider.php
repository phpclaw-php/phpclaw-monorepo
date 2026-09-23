<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the MCP Artisan command with Laravel.
 */
final class McpServiceProvider extends ServiceProvider
{
    /**
     * Register the MCP Artisan command.
     *
     * @return void
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([McpServerCommand::class]);
        }
    }
}
