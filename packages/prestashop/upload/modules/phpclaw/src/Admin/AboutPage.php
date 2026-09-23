<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Admin;

use PhpClaw\Agent\Agent;
use PhpClaw\Cloud\CloudManager;
use PhpClaw\Mcp\PhpClawMcpServer;

/**
 * About page content builder: returns marketing/product data only, never internals.
 */
final class AboutPage
{
    /**
     * Return marketing/product data for the About page view.
     *
     * @return array{version: string, packages: list<array{package: string, installed: bool, description: string}>}
     */
    public static function data(): array
    {
        return [
            'version' => class_exists(\Phpclaw::class) ? \Phpclaw::PHPCLAW_VERSION : 'unknown',
            'packages' => [
                [
                    'package' => 'phpclaw/phpclaw',
                    'installed' => class_exists(Agent::class),
                    'description' => 'Core AI agent engine. Powers all phpClaw adapters.',
                ],
                [
                    'package' => 'phpclaw/phpclaw-prestashop',
                    'installed' => class_exists(self::class),
                    'description' => 'This module.',
                ],
                [
                    'package' => 'phpclaw/phpclaw-cloud',
                    'installed' => class_exists(CloudManager::class),
                    'description' => 'Cloud transport: lifecycle event forwarding and webhook delivery to the phpClaw Cloud service.',
                ],
                [
                    'package' => 'phpclaw/phpclaw-mcp',
                    'installed' => class_exists(PhpClawMcpServer::class),
                    'description' => 'MCP server: expose your tools to Claude Desktop, Cursor, and other MCP clients.',
                ],
            ],
        ];
    }
}
