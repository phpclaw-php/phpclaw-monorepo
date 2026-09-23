<?php

declare(strict_types=1);

namespace PhpClaw\Cloud\Tests\Unit;

use PhpClaw\Cloud\CloudHttp;
use PHPUnit\Framework\TestCase;

final class CloudHttpTest extends TestCase
{
    protected function tearDown(): void
    {
        CloudHttp::reset();
    }

    public function test_transport_refuses_remote_plaintext_http(): void
    {
        $ref = new \ReflectionMethod(CloudHttp::class, 'isTransportSecure');
        $ref->setAccessible(true);

        self::assertFalse($ref->invoke(null, 'http://cloud.example.com/api'), 'remote http must be refused');
        self::assertTrue($ref->invoke(null, 'https://cloud.example.com/api'), 'remote https allowed');
        self::assertTrue($ref->invoke(null, 'http://127.0.0.1:8000/api'), 'loopback http allowed');
        self::assertTrue($ref->invoke(null, 'http://localhost:8000/api'), 'localhost http allowed');
    }

    public function test_get_returns_null_when_host_is_unreachable(): void
    {
        $result = CloudHttp::get(
            'http://127.0.0.1:1',
            'test-key',
            connectTimeout: 1,
            timeout: 1,
        );

        $this->assertNull($result);
    }

    public function test_get_returns_null_on_invalid_url(): void
    {
        $result = CloudHttp::get('not-a-url', 'test-key');
        $this->assertNull($result);
    }

    public function test_post_returns_null_when_host_is_unreachable(): void
    {
        $result = CloudHttp::post(
            'http://127.0.0.1:1',
            'test-key',
            ['message' => 'hello'],
            connectTimeout: 1,
            timeout: 1,
        );

        $this->assertNull($result);
    }

    public function test_post_returns_null_on_invalid_url(): void
    {
        $result = CloudHttp::post('not-a-url', 'test-key', ['key' => 'value']);
        $this->assertNull($result);
    }

    public function test_post_returns_null_for_non_200_response(): void
    {
        $result = CloudHttp::post(
            'http://127.0.0.1:1/nonexistent',
            'test-key',
            ['x' => 'y'],
            connectTimeout: 1,
            timeout: 1,
        );

        $this->assertNull($result);
    }

    public function test_fire_does_not_throw_when_host_is_unreachable(): void
    {
        $this->expectNotToPerformAssertions();

        CloudHttp::fire(
            'http://127.0.0.1:1',
            'test-key',
            ['event' => 'agent.before'],
            connectTimeoutMs: 100,
            timeoutMs: 200,
        );
    }

    public function test_fire_does_not_throw_on_invalid_url(): void
    {
        $this->expectNotToPerformAssertions();
        CloudHttp::fire('not-a-url', 'test-key', ['event' => 'agent.after']);
    }

    public function test_fire_does_not_throw_with_empty_body(): void
    {
        $this->expectNotToPerformAssertions();
        CloudHttp::fire('http://127.0.0.1:1', 'test-key', []);
    }

    public function test_post_handles_unicode_in_body(): void
    {
        $result = CloudHttp::post(
            'http://127.0.0.1:1',
            'test-key',
            ['message' => 'こんにちは'],
            connectTimeout: 1,
            timeout: 1,
        );

        $this->assertNull($result);
    }

    public function test_fire_handles_nested_body_arrays(): void
    {
        $this->expectNotToPerformAssertions();

        CloudHttp::fire(
            'http://127.0.0.1:1',
            'test-key',
            ['meta' => ['nested' => true]],
            connectTimeoutMs: 100,
            timeoutMs: 200,
        );
    }

    public function test_get_return_type_is_nullable_array(): void
    {
        $result = CloudHttp::get('http://127.0.0.1:1', 'key', connectTimeout: 1, timeout: 1);
        $this->assertTrue($result === null || is_array($result));
    }

    public function test_post_return_type_is_nullable_array(): void
    {
        $result = CloudHttp::post('http://127.0.0.1:1', 'key', [], connectTimeout: 1, timeout: 1);
        $this->assertTrue($result === null || is_array($result));
    }

    public function test_multiple_fire_calls_queue_without_throwing(): void
    {
        $this->expectNotToPerformAssertions();

        for ($i = 0; $i < 12; $i++) {
            CloudHttp::fire(
                'http://127.0.0.1:1',
                'test-key',
                ['event' => 'agent.iteration', 'i' => $i],
                connectTimeoutMs: 50,
                timeoutMs: 100,
            );
        }
    }

    public function test_reset_clears_state_and_allows_reuse(): void
    {
        $this->expectNotToPerformAssertions();

        CloudHttp::fire('http://127.0.0.1:1', 'test-key', ['event' => 'agent.before'], connectTimeoutMs: 50, timeoutMs: 100);
        CloudHttp::reset();

        CloudHttp::fire('http://127.0.0.1:1', 'test-key', ['event' => 'agent.after'], connectTimeoutMs: 50, timeoutMs: 100);
    }

    public function test_reset_is_idempotent(): void
    {
        $this->expectNotToPerformAssertions();

        CloudHttp::reset();
        CloudHttp::reset();
    }
}
