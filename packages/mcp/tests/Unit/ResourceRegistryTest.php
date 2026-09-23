<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit;

use PhpClaw\Mcp\ResourceRegistry;
use PHPUnit\Framework\TestCase;

final class ResourceRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ResourceRegistry::reset();
    }

    protected function tearDown(): void
    {
        ResourceRegistry::reset();
    }

    public function test_register_and_has(): void
    {
        ResourceRegistry::register(
            uri: 'file:///logs/app.log',
            name: 'App Log',
            description: 'Application log file.',
            reader: fn () => 'log content',
        );

        self::assertTrue(ResourceRegistry::has('file:///logs/app.log'));
        self::assertFalse(ResourceRegistry::has('file:///nonexistent'));
    }

    public function test_count_reflects_registrations(): void
    {
        self::assertSame(0, ResourceRegistry::count());

        ResourceRegistry::register('uri:1', 'One', 'First.', fn () => '');
        self::assertSame(1, ResourceRegistry::count());

        ResourceRegistry::register('uri:2', 'Two', 'Second.', fn () => '');
        self::assertSame(2, ResourceRegistry::count());
    }

    public function test_registering_same_uri_overwrites(): void
    {
        ResourceRegistry::register('uri:x', 'Old', 'Old.', fn () => 'old');
        ResourceRegistry::register('uri:x', 'New', 'New.', fn () => 'new');

        self::assertSame(1, ResourceRegistry::count());
        self::assertSame('new', ResourceRegistry::read('uri:x'));
    }

    public function test_schemas_returns_empty_when_nothing_registered(): void
    {
        self::assertSame([], ResourceRegistry::schemas());
    }

    public function test_schemas_returns_correct_structure(): void
    {
        ResourceRegistry::register(
            uri: 'db://users',
            name: 'Users Table',
            description: 'Current user records.',
            reader: fn () => '[]',
            mimeType: 'application/json',
        );

        $schemas = ResourceRegistry::schemas();

        self::assertCount(1, $schemas);
        self::assertSame('db://users', $schemas[0]['uri']);
        self::assertSame('Users Table', $schemas[0]['name']);
        self::assertSame('Current user records.', $schemas[0]['description']);
        self::assertSame('application/json', $schemas[0]['mimeType']);
    }

    public function test_schemas_uses_default_mime_type(): void
    {
        ResourceRegistry::register('uri:plain', 'Plain', 'Plain text.', fn () => 'text');

        $schemas = ResourceRegistry::schemas();

        self::assertSame('text/plain', $schemas[0]['mimeType']);
    }

    public function test_schemas_returns_all_registered(): void
    {
        ResourceRegistry::register('uri:a', 'A', 'First.', fn () => 'a');
        ResourceRegistry::register('uri:b', 'B', 'Second.', fn () => 'b');
        ResourceRegistry::register('uri:c', 'C', 'Third.', fn () => 'c');

        self::assertCount(3, ResourceRegistry::schemas());
    }

    public function test_read_calls_reader_and_returns_content(): void
    {
        ResourceRegistry::register('uri:log', 'Log', 'Log.', fn () => 'hello from reader');

        self::assertSame('hello from reader', ResourceRegistry::read('uri:log'));
    }

    public function test_read_throws_for_unknown_uri(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/i');

        ResourceRegistry::read('uri:ghost');
    }

    public function test_read_calls_reader_on_every_call(): void
    {
        $counter = 0;
        ResourceRegistry::register('uri:counter', 'Counter', 'Counts.', function () use (&$counter): string {
            $counter++;

            return (string) $counter;
        });

        self::assertSame('1', ResourceRegistry::read('uri:counter'));
        self::assertSame('2', ResourceRegistry::read('uri:counter'));
        self::assertSame(2, $counter);
    }

    public function test_reset_clears_all_resources(): void
    {
        ResourceRegistry::register('uri:a', 'A', 'First.', fn () => 'a');
        ResourceRegistry::register('uri:b', 'B', 'Second.', fn () => 'b');

        ResourceRegistry::reset();

        self::assertSame(0, ResourceRegistry::count());
        self::assertSame([], ResourceRegistry::schemas());
        self::assertFalse(ResourceRegistry::has('uri:a'));
    }
}
