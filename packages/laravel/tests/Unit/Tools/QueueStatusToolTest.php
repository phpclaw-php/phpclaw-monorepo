<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Tools;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Tools\QueueStatusTool;

final class QueueStatusToolTest extends TestCase
{
    private QueueStatusTool $tool;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new QueueStatusTool;
    }

    public function test_name_returns_queue_status(): void
    {
        $this->assertSame('queue_status', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $description = $this->tool->description();

        $this->assertStringContainsString('REPORT Laravel queue status', $description);
        $this->assertStringContainsString('never dispatches or modifies jobs', $description);
    }

    public function test_input_schema_is_valid(): void
    {
        $schema = $this->tool->inputSchema();

        $this->assertSame('object', $schema['type']);
    }

    public function test_required_capability_returns_chat_ability(): void
    {
        $this->assertSame('phpclaw.chat', $this->tool->requiredCapability());
    }

    public function test_execute_returns_success_envelope_shape(): void
    {
        $envelope = json_decode($this->tool->execute([]), associative: true);

        $this->assertIsArray($envelope);
        $this->assertTrue($envelope['success']);
        $this->assertArrayHasKey('data', $envelope);
        $this->assertArrayHasKey('meta', $envelope);
        $this->assertArrayHasKey('warnings', $envelope);
    }

    public function test_execute_returns_json_with_connection_field(): void
    {
        $envelope = json_decode($this->tool->execute([]), associative: true);

        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('connection', $envelope['data']);
        $this->assertSame('sync', $envelope['data']['connection']);
    }

    public function test_execute_returns_pending_and_failed_fields(): void
    {
        $envelope = json_decode($this->tool->execute([]), associative: true);

        $this->assertIsArray($envelope);
        $this->assertArrayHasKey('pending', $envelope['data']);
        $this->assertArrayHasKey('failed', $envelope['data']);
    }

    public function test_execute_returns_null_counts_when_tables_missing(): void
    {
        $envelope = json_decode($this->tool->execute([]), associative: true);

        $this->assertIsArray($envelope);
        $this->assertNull($envelope['data']['pending']);
        $this->assertNull($envelope['data']['failed']);

        $codes = array_column($envelope['warnings'], 'code');
        $this->assertContains('NOT_FOUND', $codes);
        $this->assertCount(2, $envelope['warnings']);
    }

    public function test_execute_is_read_only(): void
    {
        $first = $this->tool->execute([]);
        $second = $this->tool->execute([]);

        $this->assertSame($first, $second);
    }

    public function test_execute_returns_forbidden_when_no_authenticated_user_and_gate_undefined(): void
    {
        $ref = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $ref->setAccessible(true);
        $ref->setValue($this->app, false);

        $envelope = json_decode($this->tool->execute([]), associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('FORBIDDEN', $envelope['error']['code']);
    }
}
