<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Admin;

/**
 * About page content builder for the phpClaw OpenCart admin module.
 */
final class AboutPage
{
    /**
     * Return the About page content array (version + packages) for the admin template.
     *
     * @return array{version: string, packages: list<array{name: string, description: string, installed: bool}>}
     */
    public static function data(): array
    {
        return [
            'version' => defined('PHPCLAW_VERSION') ? PHPCLAW_VERSION : 'unknown',
            'packages' => self::packages(),
        ];
    }

    /**
     * Build the 4-package ecosystem list with live install detection.
     *
     * @return list<array{name: string, description: string, installed: bool}>
     */
    public static function packages(): array
    {
        $rows = [
            ['phpclaw/phpclaw',          'PhpClaw\\Claw',                  'Core AI agent engine: providers, tools, guards, hooks, memory, skills all included. Powers every phpClaw adapter.'],
            ['phpclaw/phpclaw-opencart', 'PhpClaw\\OpenCart\\Plugin',      'This extension.'],
            ['phpclaw/phpclaw-cloud',    'PhpClaw\\Cloud\\CloudManager',   'Cloud transport layer, bundled with every install. Activates when a cloud key is configured.'],
            ['phpclaw/phpclaw-mcp',      'PhpClaw\\Mcp\\PhpClawMcpServer', 'MCP server. Expose your tools to Claude Desktop, Cursor, and other MCP clients.'],
        ];

        $out = [];
        foreach ($rows as [$name, $sentinel, $desc]) {
            $out[] = [
                'name' => $name,
                'description' => $desc,
                'installed' => class_exists($sentinel),
            ];
        }

        return $out;
    }
}
