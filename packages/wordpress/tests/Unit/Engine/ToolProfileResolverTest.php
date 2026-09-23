<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Engine;

use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolProfileResolver;
use PHPUnit\Framework\TestCase;

final class ToolProfileResolverTest extends TestCase
{
    private const WP_GROUPS = [
        'group:content' => ['wp_query', 'wp_taxonomy', 'wp_comments', 'wp_media'],
        'group:admin' => ['wp_users', 'wp_plugins', 'wp_option', 'wp_cron'],
        'group:system' => ['db_query', 'read_log', 'file_read', 'file_write'],
        'group:nav' => ['wp_menus', 'wp_taxonomy'],
    ];

    public function test_deny_group_system_removes_all_system_tools(): void
    {
        $tools = $this->makeNamedTools(['wp_query', 'wp_users', 'db_query', 'read_log', 'file_read', 'file_write']);

        $result = ToolProfileResolver::filter($tools, ['group:system'], self::WP_GROUPS);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('db_query', $names);
        $this->assertNotContains('read_log', $names);
        $this->assertNotContains('file_read', $names);
        $this->assertNotContains('file_write', $names);
        $this->assertContains('wp_query', $names);
        $this->assertContains('wp_users', $names);
    }

    public function test_deny_group_content_removes_content_tools(): void
    {
        $tools = $this->makeNamedTools(['wp_query', 'wp_taxonomy', 'wp_comments', 'wp_media', 'wp_users']);

        $result = ToolProfileResolver::filter($tools, ['group:content'], self::WP_GROUPS);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('wp_query', $names);
        $this->assertNotContains('wp_taxonomy', $names);
        $this->assertNotContains('wp_comments', $names);
        $this->assertNotContains('wp_media', $names);
        $this->assertContains('wp_users', $names);
    }

    public function test_deny_group_admin_removes_admin_tools(): void
    {
        $tools = $this->makeNamedTools(['wp_query', 'wp_users', 'wp_plugins', 'wp_option', 'wp_cron']);

        $result = ToolProfileResolver::filter($tools, ['group:admin'], self::WP_GROUPS);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('wp_users', $names);
        $this->assertNotContains('wp_plugins', $names);
        $this->assertNotContains('wp_option', $names);
        $this->assertNotContains('wp_cron', $names);
        $this->assertContains('wp_query', $names);
    }

    private function makeNamedTools(array $names): array
    {
        return array_map(
            fn (string $name) => $this->createFakeTool($name),
            $names,
        );
    }

    private function createFakeTool(string $name): ToolInterface
    {
        return new class($name) implements ToolInterface
        {
            public function __construct(private readonly string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return '';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass];
            }

            public function execute(array $input): string
            {
                return '';
            }
        };
    }
}
