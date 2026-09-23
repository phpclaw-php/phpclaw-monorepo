<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Defaults;

use PhpClaw\Config\EnvVars;
use PhpClaw\Mcp\ResourceRegistry;
use PhpClaw\Tools\ToolRegistry;

/**
 * Registers the built-in phpClaw MCP resources.
 */
final class DefaultResources
{
    /**
     * Register default resources with the ResourceRegistry.
     *
     * @param  ToolRegistry|null  $toolRegistry  Optional, used by the phpclaw://tools resource.
     * @return void
     */
    public static function register(?ToolRegistry $toolRegistry = null): void
    {
        ResourceRegistry::register(
            uri: 'phpclaw://config',
            name: 'phpClaw Configuration',
            description: 'Sanitized application configuration (API keys redacted)',
            reader: static function (): string {
                $config = [];

                $envKeys = [
                    EnvVars::PHPCLAW_PROVIDER,
                    EnvVars::PHPCLAW_MODEL,
                    'PHPCLAW_MAX_TOKENS',
                    'PHPCLAW_MAX_ITERATIONS',
                ];

                foreach ($envKeys as $key) {
                    $value = getenv($key);
                    if ($value !== false) {
                        $config[$key] = $value;
                    }
                }

                $providerKeys = [
                    EnvVars::ANTHROPIC_API_KEY,
                    EnvVars::OPENAI_API_KEY,
                    EnvVars::GROQ_API_KEY,
                    EnvVars::GEMINI_API_KEY,
                ];

                foreach ($providerKeys as $key) {
                    $config[$key] = getenv($key) !== false ? '***configured***' : 'not set';
                }

                return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
            },
            mimeType: 'application/json',
        );

        ResourceRegistry::register(
            uri: 'phpclaw://tools',
            name: 'Registered Tools',
            description: 'List of all registered phpClaw tool names and descriptions',
            reader: static function () use ($toolRegistry): string {
                if ($toolRegistry === null) {
                    return json_encode(['tools' => []], JSON_PRETTY_PRINT) ?: '{}';
                }

                $tools = [];
                foreach ($toolRegistry->all() as $tool) {
                    $tools[] = [
                        'name' => $tool->name(),
                        'description' => $tool->description(),
                    ];
                }

                return json_encode(['tools' => $tools], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
            },
            mimeType: 'application/json',
        );
    }
}
