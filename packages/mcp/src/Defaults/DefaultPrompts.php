<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Defaults;

use PhpClaw\Mcp\PromptRegistry;

/**
 * Registers the built-in phpClaw MCP prompt templates.
 */
final class DefaultPrompts
{
    /**
     * Register default prompts with the PromptRegistry.
     *
     * @return void
     */
    public static function register(): void
    {
        PromptRegistry::register(
            name: 'debug',
            description: 'Check application health: review logs, list tools, check memory',
            arguments: [],
            renderer: static function (array $args): array {
                return [
                    [
                        'role' => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => 'Check application health: review recent logs for errors, list all registered tools and their status, and check memory driver connectivity.',
                        ],
                    ],
                ];
            },
        );

        PromptRegistry::register(
            name: 'db-schema',
            description: 'List all database tables and describe their columns',
            arguments: [
                [
                    'name' => 'table',
                    'description' => 'Specific table name to describe (optional, omit for all tables)',
                    'required' => false,
                ],
            ],
            renderer: static function (array $args): array {
                $table = $args['table'] ?? null;

                $text = $table !== null
                    ? "List all columns in the database table '{$table}': include column name, type, nullable, default value, and any indexes or constraints."
                    : 'List all database tables and describe their columns: include column name, type, nullable, default value, and any indexes or constraints.';

                return [
                    [
                        'role' => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => $text,
                        ],
                    ],
                ];
            },
        );
    }
}
