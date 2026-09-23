<?php

declare(strict_types=1);

namespace PhpClaw\Cloud\Tests\Unit;

use PhpClaw\Cloud\CloudPayloadBuilder;
use PHPUnit\Framework\TestCase;

final class CloudWebhookHookGenericEventTest extends TestCase
{
    private function build(string $event, array $context): array
    {
        return (new CloudPayloadBuilder)->build($event, $context);
    }

    public function test_unknown_event_routes_to_generic_payload_via_wildcard_instance(): void
    {
        $payload = $this->build('my.custom.event', [
            'run_id' => 'run-1',
            'parent_run_id' => 'parent-1',
            'foo' => 'bar',
        ]);

        $this->assertSame('my.custom.event', $payload['event']);
        $this->assertArrayHasKey('ts', $payload);
        $this->assertSame('run-1', $payload['run_id']);
        $this->assertSame('parent-1', $payload['parent_run_id']);
        $this->assertIsArray($payload['payload']);
        $this->assertSame('bar', $payload['payload']['foo']);
    }

    public function test_generic_payload_truncates_strings_over_8_kb(): void
    {
        $bigStr = str_repeat('x', 10000);
        $payload = $this->build('bomb.event', ['data' => $bigStr]);

        $this->assertLessThanOrEqual(8192 + 20, strlen($payload['payload']['data']));
        $this->assertStringEndsWith('…[truncated]', $payload['payload']['data']);
    }

    public function test_generic_payload_drops_arrays_over_100_items(): void
    {
        $bigArr = array_fill(0, 200, 'item');
        $payload = $this->build('bomb.event', ['list' => $bigArr]);

        $this->assertIsString($payload['payload']['list']);
        $this->assertStringStartsWith('[array-too-large:', $payload['payload']['list']);
    }

    public function test_generic_payload_redacts_secret_keys(): void
    {
        $payload = $this->build('login.attempted', [
            'username' => 'alice',
            'password' => 'hunter2',
            'api_key' => 'sk-abc',
            'token' => 't0k3n',
            'secret' => 's3cr3t',
        ]);

        $this->assertSame('alice', $payload['payload']['username']);
        $this->assertSame('[redacted]', $payload['payload']['password']);
        $this->assertSame('[redacted]', $payload['payload']['api_key']);
        $this->assertSame('[redacted]', $payload['payload']['token']);
        $this->assertSame('[redacted]', $payload['payload']['secret']);
    }

    public function test_typed_event_still_uses_typed_builder_when_not_wildcard_instance(): void
    {
        $payload = $this->build('agent.before', [
            'run_id' => 'run-1',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
        ]);

        $this->assertSame('agent.before', $payload['event']);
        $this->assertSame('run-1', $payload['run_id']);
        $this->assertArrayNotHasKey('payload', $payload);
    }
}
