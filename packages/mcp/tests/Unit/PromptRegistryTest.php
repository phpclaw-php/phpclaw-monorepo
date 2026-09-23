<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit;

use PhpClaw\Mcp\PromptRegistry;
use PHPUnit\Framework\TestCase;

final class PromptRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        PromptRegistry::reset();
    }

    protected function tearDown(): void
    {
        PromptRegistry::reset();
    }

    public function test_register_and_has(): void
    {
        PromptRegistry::register(
            name: 'greet',
            description: 'Greet the user.',
            arguments: [],
            renderer: fn (array $args) => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Hello!']],
            ],
        );

        self::assertTrue(PromptRegistry::has('greet'));
        self::assertFalse(PromptRegistry::has('farewell'));
    }

    public function test_count_reflects_registrations(): void
    {
        self::assertSame(0, PromptRegistry::count());

        PromptRegistry::register('p1', 'One', [], fn ($a) => []);
        self::assertSame(1, PromptRegistry::count());

        PromptRegistry::register('p2', 'Two', [], fn ($a) => []);
        self::assertSame(2, PromptRegistry::count());
    }

    public function test_registering_same_name_overwrites(): void
    {
        PromptRegistry::register('same', 'Old', [], fn ($a) => [['text' => 'old']]);
        PromptRegistry::register('same', 'New', [], fn ($a) => [['text' => 'new']]);

        self::assertSame(1, PromptRegistry::count());

        $rendered = PromptRegistry::render('same', []);
        self::assertSame([['text' => 'new']], $rendered['messages']);
    }

    public function test_schemas_returns_empty_when_nothing_registered(): void
    {
        self::assertSame([], PromptRegistry::schemas());
    }

    public function test_schemas_returns_correct_structure(): void
    {
        $args = [
            ['name' => 'date', 'description' => 'Date in YYYY-MM-DD.', 'required' => true],
        ];

        PromptRegistry::register(
            name: 'summarise-logs',
            description: 'Summarise logs for a date.',
            arguments: $args,
            renderer: fn ($a) => [],
        );

        $schemas = PromptRegistry::schemas();

        self::assertCount(1, $schemas);
        self::assertSame('summarise-logs', $schemas[0]['name']);
        self::assertSame('Summarise logs for a date.', $schemas[0]['description']);
        self::assertSame($args, $schemas[0]['arguments']);
    }

    public function test_schemas_returns_all_registered(): void
    {
        PromptRegistry::register('p1', 'One', [], fn ($a) => []);
        PromptRegistry::register('p2', 'Two', [], fn ($a) => []);
        PromptRegistry::register('p3', 'Three', [], fn ($a) => []);

        self::assertCount(3, PromptRegistry::schemas());
    }

    public function test_render_returns_description_and_messages(): void
    {
        PromptRegistry::register(
            name: 'hello',
            description: 'Say hello.',
            arguments: [],
            renderer: fn (array $args) => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Hello!']],
            ],
        );

        $result = PromptRegistry::render('hello', []);

        self::assertSame('Say hello.', $result['description']);
        self::assertCount(1, $result['messages']);
        self::assertSame('user', $result['messages'][0]['role']);
    }

    public function test_render_passes_arguments_to_renderer(): void
    {
        PromptRegistry::register(
            name: 'greet-user',
            description: 'Greet by name.',
            arguments: [['name' => 'username', 'description' => 'The username.', 'required' => true]],
            renderer: fn (array $args) => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => "Hello, {$args['username']}!"]],
            ],
        );

        $result = PromptRegistry::render('greet-user', ['username' => 'Alice']);

        self::assertSame('Hello, Alice!', $result['messages'][0]['content']['text']);
    }

    public function test_render_throws_for_unknown_prompt(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        PromptRegistry::render('nonexistent', []);
    }

    public function test_render_calls_renderer_on_every_call(): void
    {
        $callCount = 0;

        PromptRegistry::register(
            name: 'counted',
            description: 'Counted.',
            arguments: [],
            renderer: function (array $args) use (&$callCount): array {
                $callCount++;

                return [];
            },
        );

        PromptRegistry::render('counted', []);
        PromptRegistry::render('counted', []);

        self::assertSame(2, $callCount);
    }

    public function test_reset_clears_all_prompts(): void
    {
        PromptRegistry::register('p1', 'One', [], fn ($a) => []);
        PromptRegistry::register('p2', 'Two', [], fn ($a) => []);

        PromptRegistry::reset();

        self::assertSame(0, PromptRegistry::count());
        self::assertSame([], PromptRegistry::schemas());
        self::assertFalse(PromptRegistry::has('p1'));
    }
}
