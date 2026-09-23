<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit\Defaults;

use PhpClaw\Mcp\Defaults\DefaultPrompts;
use PhpClaw\Mcp\PromptRegistry;
use PHPUnit\Framework\TestCase;

final class DefaultPromptsTest extends TestCase
{
    protected function setUp(): void
    {
        PromptRegistry::reset();
    }

    protected function tearDown(): void
    {
        PromptRegistry::reset();
    }

    public function test_register_adds_debug_prompt(): void
    {
        DefaultPrompts::register();

        self::assertTrue(PromptRegistry::has('debug'));
    }

    public function test_register_adds_db_schema_prompt(): void
    {
        DefaultPrompts::register();

        self::assertTrue(PromptRegistry::has('db-schema'));
    }

    public function test_registers_exactly_two_prompts(): void
    {
        DefaultPrompts::register();

        self::assertSame(2, PromptRegistry::count());
    }

    public function test_debug_prompt_renders_messages(): void
    {
        DefaultPrompts::register();

        $result = PromptRegistry::render('debug', []);

        self::assertArrayHasKey('description', $result);
        self::assertArrayHasKey('messages', $result);
        self::assertCount(1, $result['messages']);
        self::assertSame('user', $result['messages'][0]['role']);
    }

    public function test_debug_prompt_message_contains_health_check(): void
    {
        DefaultPrompts::register();

        $result = PromptRegistry::render('debug', []);
        $text = $result['messages'][0]['content']['text'];

        self::assertStringContainsString('health', $text);
        self::assertStringContainsString('logs', $text);
        self::assertStringContainsString('tools', $text);
    }

    public function test_db_schema_prompt_renders_generic_without_table(): void
    {
        DefaultPrompts::register();

        $result = PromptRegistry::render('db-schema', []);
        $text = $result['messages'][0]['content']['text'];

        self::assertStringContainsString('all database tables', $text);
    }

    public function test_db_schema_prompt_renders_specific_table(): void
    {
        DefaultPrompts::register();

        $result = PromptRegistry::render('db-schema', ['table' => 'users']);
        $text = $result['messages'][0]['content']['text'];

        self::assertStringContainsString('users', $text);
        self::assertStringNotContainsString('all database tables', $text);
    }

    public function test_db_schema_prompt_has_optional_table_argument(): void
    {
        DefaultPrompts::register();

        $schemas = PromptRegistry::schemas();
        $dbSchema = null;

        foreach ($schemas as $schema) {
            if ($schema['name'] === 'db-schema') {
                $dbSchema = $schema;
                break;
            }
        }

        self::assertNotNull($dbSchema);
        self::assertCount(1, $dbSchema['arguments']);
        self::assertSame('table', $dbSchema['arguments'][0]['name']);
        self::assertFalse($dbSchema['arguments'][0]['required']);
    }

    public function test_debug_prompt_has_no_arguments(): void
    {
        DefaultPrompts::register();

        $schemas = PromptRegistry::schemas();
        $debug = null;

        foreach ($schemas as $schema) {
            if ($schema['name'] === 'debug') {
                $debug = $schema;
                break;
            }
        }

        self::assertNotNull($debug);
        self::assertEmpty($debug['arguments']);
    }
}
