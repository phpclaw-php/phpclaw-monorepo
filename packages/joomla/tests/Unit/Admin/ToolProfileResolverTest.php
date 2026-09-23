<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Admin;

use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolProfileResolver;
use PHPUnit\Framework\TestCase;

final class ToolProfileResolverTest extends TestCase
{
    private const JOOMLA_GROUPS = [
        'group:content' => ['joomla_articles', 'joomla_categories'],
        'group:admin' => ['joomla_users', 'joomla_extensions'],
        'group:system' => ['joomla_database_query'],
    ];

    public function test_deny_group_system_removes_database_tool(): void
    {
        $tools = $this->makeNamedTools(['joomla_articles', 'joomla_users', 'joomla_categories', 'joomla_extensions', 'joomla_database_query']);

        $result = ToolProfileResolver::filter($tools, ['group:system'], self::JOOMLA_GROUPS);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('joomla_database_query', $names);
        $this->assertContains('joomla_articles', $names);
    }

    public function test_deny_group_content_removes_articles_and_categories(): void
    {
        $tools = $this->makeNamedTools(['joomla_articles', 'joomla_users', 'joomla_categories', 'joomla_extensions', 'joomla_database_query']);

        $result = ToolProfileResolver::filter($tools, ['group:content'], self::JOOMLA_GROUPS);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('joomla_articles', $names);
        $this->assertNotContains('joomla_categories', $names);
        $this->assertContains('joomla_users', $names);
        $this->assertContains('joomla_extensions', $names);
        $this->assertContains('joomla_database_query', $names);
    }

    public function test_deny_group_admin_removes_users_and_extensions(): void
    {
        $tools = $this->makeNamedTools(['joomla_articles', 'joomla_users', 'joomla_categories', 'joomla_extensions', 'joomla_database_query']);

        $result = ToolProfileResolver::filter($tools, ['group:admin'], self::JOOMLA_GROUPS);

        $names = array_map(fn (ToolInterface $t) => $t->name(), $result);
        $this->assertNotContains('joomla_users', $names);
        $this->assertNotContains('joomla_extensions', $names);
        $this->assertContains('joomla_articles', $names);
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
