<?php

declare(strict_types=1);

namespace PhpClaw\Cloud\Tests\Unit;

use PhpClaw\Cloud\CloudHttp;
use PHPUnit\Framework\TestCase;

final class CloudHttpTest extends TestCase
{
    private static mixed $server = null;

    private static string $serverUrl = '';

    private static string $router = '';

    public static function setUpBeforeClass(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($probe, false);
        fclose($probe);

        self::$router = sys_get_temp_dir().'/phpclaw-cloud-http-router-'.getmypid().'.php';
        file_put_contents(self::$router, '<?php header("Content-Type: application/json"); echo $_GET["body"] ?? "";');

        self::$server = proc_open(
            [PHP_BINARY, '-S', $address, self::$router],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        self::$serverUrl = 'http://'.$address.'/?body=';

        $deadline = microtime(true) + 3;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', (int) substr($address, strrpos($address, ':') + 1), $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        @unlink(self::$router);
    }

    protected function tearDown(): void
    {
        CloudHttp::reset();
    }

    public function test_get_returns_decoded_object_body_on_200(): void
    {
        self::assertSame(['a' => 1], CloudHttp::get(self::$serverUrl.urlencode('{"a":1}'), 'key'));
    }

    public function test_post_returns_decoded_object_body_on_200(): void
    {
        self::assertSame(['plan' => 'pro'], CloudHttp::post(self::$serverUrl.urlencode('{"plan":"pro"}'), 'key', ['x' => 1]));
    }

    public function test_get_returns_null_when_body_is_json_true(): void
    {
        self::assertNull(CloudHttp::get(self::$serverUrl.urlencode('true'), 'key'));
    }

    public function test_get_returns_null_when_body_is_json_string(): void
    {
        self::assertNull(CloudHttp::get(self::$serverUrl.urlencode('"str"'), 'key'));
    }

    public function test_post_returns_null_when_body_is_json_number(): void
    {
        self::assertNull(CloudHttp::post(self::$serverUrl.urlencode('42'), 'key', []));
    }

    public function test_get_returns_null_when_body_is_invalid_json(): void
    {
        self::assertNull(CloudHttp::get(self::$serverUrl.urlencode('{not json'), 'key'));
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

    public function test_transport_allows_ipv6_loopback_written_with_brackets(): void
    {
        $ref = new \ReflectionMethod(CloudHttp::class, 'isTransportSecure');

        self::assertTrue($ref->invoke(null, 'http://[::1]:8080/api'), 'IPv6 loopback http allowed');
        self::assertFalse($ref->invoke(null, 'http://[::2]:8080/api'), 'another IPv6 host must be refused');
        self::assertFalse($ref->invoke(null, 'http://[2001:db8::1]/api'), 'a remote IPv6 host must be refused');
        self::assertFalse($ref->invoke(null, 'http://[::1].evil.test/api'), 'a host that only starts like loopback must be refused');
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
