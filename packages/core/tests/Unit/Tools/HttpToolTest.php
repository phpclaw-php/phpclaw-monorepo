<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Support\SsrfValidator;
use PhpClaw\Tools\HttpTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpToolTest extends TestCase
{
    private HttpTool $tool;

    protected function setUp(): void
    {
        $this->tool = new HttpTool;
    }

    public function test_name_returns_http_request(): void
    {
        $this->assertSame('http_request', $this->tool->name());
    }

    public function test_description_is_non_empty(): void
    {
        $this->assertNotEmpty($this->tool->description());
    }

    public function test_input_schema_has_required_url(): void
    {
        $schema = $this->tool->inputSchema();
        $this->assertArrayHasKey('url', $schema['properties']);
        $this->assertContains('url', $schema['required']);
    }

    public function test_input_schema_has_method_enum(): void
    {
        $schema = $this->tool->inputSchema();
        $this->assertArrayHasKey('method', $schema['properties']);
        $this->assertContains('GET', $schema['properties']['method']['enum']);
        $this->assertContains('POST', $schema['properties']['method']['enum']);
    }

    public function test_throws_when_url_is_empty(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => '']);
    }

    public function test_throws_when_url_key_is_missing(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute([]);
    }

    #[DataProvider('invalidSchemeProvider')]
    public function test_throws_for_invalid_url_scheme(string $url): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => $url]);
    }

    public static function invalidSchemeProvider(): array
    {
        return [
            ['ftp://example.com/file.txt'],
            ['file:///etc/passwd'],
            ['ssh://user@host'],
            ['javascript:alert(1)'],
            ['data:text/plain,hello'],
        ];
    }

    #[DataProvider('blockedHostProvider')]
    public function test_throws_for_blocked_internal_host(string $url): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => $url]);
    }

    public static function blockedHostProvider(): array
    {
        return [
            ['http://localhost/secret'],
            ['http://127.0.0.1/admin'],
            ['http://127.1.2.3/test'],
            ['http://0.0.0.0/'],
            ['http://10.0.0.1/internal'],
            ['http://10.255.255.255/internal'],
            ['http://192.168.1.1/router'],
            ['http://192.168.0.0/config'],
            ['http://169.254.169.254/metadata'],
            ['https://169.254.0.1/'],
        ];
    }

    public function test_throws_for_disallowed_http_method(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => 'https://example.com', 'method' => 'DELETE']);
    }

    public function test_throws_for_put_method(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => 'https://example.com', 'method' => 'PUT']);
    }

    public function test_throws_for_patch_method(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => 'https://example.com', 'method' => 'PATCH']);
    }

    public function test_tool_exception_is_thrown_on_blocked_host(): void
    {
        try {
            $this->tool->execute(['url' => 'http://127.0.0.1/']);
        } catch (ToolException $e) {
            $this->assertInstanceOf(ToolException::class, $e);
            $this->assertStringContainsString('blocked', strtolower($e->getMessage()));

            return;
        }

        $this->fail('Expected ToolException was not thrown');
    }

    public function test_tool_exception_is_thrown_on_invalid_scheme(): void
    {
        try {
            $this->tool->execute(['url' => 'file:///etc/passwd']);
        } catch (ToolException $e) {
            $this->assertStringContainsString('scheme', strtolower($e->getMessage()));

            return;
        }

        $this->fail('Expected ToolException was not thrown');
    }

    public function test_blocks_ipv6_loopback_in_url(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => 'http://[::1]/']);
    }

    public function test_blocks_ipv6_unique_local_fc00(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => 'http://[fc00::1]/internal']);
    }

    public function test_blocks_ipv6_unique_local_fd00(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => 'http://[fd00::1]/private']);
    }

    public function test_blocks_ipv6_link_local(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => 'http://[fe80::1]/link-local']);
    }

    public function test_blocks_ipv4_mapped_ipv6_private(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => 'http://[::ffff:127.0.0.1]/']);
    }

    public function test_blocks_ipv4_mapped_ipv6_rfc1918(): void
    {
        $this->expectException(ToolException::class);
        $this->tool->execute(['url' => 'http://[::ffff:192.168.1.1]/']);
    }

    public function test_post_method_does_not_throw_method_exception(): void
    {
        try {
            $this->tool->execute(['url' => 'http://127.0.0.1/', 'method' => 'POST']);
        } catch (ToolException $e) {
            $this->assertStringNotContainsString('not allowed', strtolower($e->getMessage()));

            return;
        }
        $this->assertTrue(true);
    }

    public function test_max_response_bytes_default(): void
    {
        $this->assertSame(HttpTool::DEFAULT_MAX_RESPONSE_BYTES, $this->tool->maxResponseBytes());
    }

    public function test_max_response_bytes_can_be_overridden(): void
    {
        $tool = new HttpTool(maxResponseBytes: 1024);
        $this->assertSame(1024, $tool->maxResponseBytes());
    }

    public function test_timeout_seconds_default(): void
    {
        $this->assertSame(HttpTool::DEFAULT_TIMEOUT_SECONDS, $this->tool->timeoutSeconds());
    }

    public function test_timeout_seconds_can_be_overridden(): void
    {
        $tool = new HttpTool(timeoutSeconds: 30);
        $this->assertSame(30, $tool->timeoutSeconds());
    }

    public function test_build_curl_headers_formats_kv_pairs(): void
    {
        $result = $this->invokePrivate('buildCurlHeaders', [['X-Foo' => 'bar', 'Accept' => 'json']]);
        $this->assertSame(['X-Foo: bar', 'Accept: json'], $result);
    }

    public function test_build_curl_headers_strips_crlf_to_prevent_header_injection(): void
    {
        $result = $this->invokePrivate('buildCurlHeaders', [[
            "X-Bad\r\nInjected-Header" => "value\r\nX-Sneaky: yes",
        ]]);
        $this->assertCount(1, $result);
        $this->assertStringNotContainsString("\r", $result[0]);
        $this->assertStringNotContainsString("\n", $result[0]);
        $this->assertSame('X-BadInjected-Header: valueX-Sneaky: yes', $result[0]);
    }

    public function test_build_curl_headers_empty_input_returns_empty_array(): void
    {
        $this->assertSame([], $this->invokePrivate('buildCurlHeaders', [[]]));
    }

    public function test_truncate_response_returns_short_response_unchanged(): void
    {
        $tool = new HttpTool(maxResponseBytes: 100);
        $body = 'short body';
        $this->assertSame($body, $this->invokePrivate('truncateResponse', [$body], $tool));
    }

    public function test_truncate_response_truncates_long_response_with_marker(): void
    {
        $tool = new HttpTool(maxResponseBytes: 10);
        $body = str_repeat('a', 100);
        $result = $this->invokePrivate('truncateResponse', [$body], $tool);
        $this->assertStringStartsWith(str_repeat('a', 10), $result);
        $this->assertStringContainsString('[Response truncated at 10 bytes]', $result);
    }

    public function test_truncate_response_exactly_at_limit_returns_unchanged(): void
    {
        $tool = new HttpTool(maxResponseBytes: 10);
        $body = str_repeat('b', 10);
        $this->assertSame($body, $this->invokePrivate('truncateResponse', [$body], $tool));
    }

    public function test_capture_response_header_parses_name_and_value(): void
    {
        $headers = [];
        $this->invokePrivate('captureResponseHeader', ["Content-Type: application/json\r\n", &$headers]);
        $this->assertSame(['Content-Type' => 'application/json'], $headers);
    }

    public function test_capture_response_header_ignores_status_line(): void
    {
        $headers = [];
        $this->invokePrivate('captureResponseHeader', ["HTTP/1.1 200 OK\r\n", &$headers]);
        $this->assertSame([], $headers);
    }

    public function test_capture_response_header_ignores_blank_line(): void
    {
        $headers = [];
        $this->invokePrivate('captureResponseHeader', ["\r\n", &$headers]);
        $this->assertSame([], $headers);
    }

    public function test_build_response_payload_produces_expected_shape(): void
    {
        $envelope = (array) $this->invokePrivate('buildResponsePayload', [200, ['X-Foo' => 'bar'], 'hello']);
        $this->assertSame(200, $envelope['status']);
        $this->assertSame(['X-Foo' => 'bar'], $envelope['headers']);
        $this->assertSame('hello', $envelope['body']);
    }

    public function test_validate_method_lowercase_input_is_uppercased(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/blocked.*private/i');
        $this->tool->execute(['url' => 'http://127.0.0.1/', 'method' => 'get']);
    }

    public function test_execute_strips_whitespace_around_url(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('No URL provided.');
        $this->tool->execute(['url' => '   ']);
    }

    public function test_execute_throws_when_headers_not_array(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/blocked|private/i');
        $this->tool->execute(['url' => 'http://10.0.0.1/', 'headers' => 'not-an-array']);
    }

    public function test_request_returns_body_from_real_http_endpoint(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer('<?php echo "hello-from-test-server";');
        try {
            $result = $this->invokePrivate('request', [
                "http://127.0.0.1:{$port}/index.php",
                'GET',
                '',
                [],
            ]);
            $envelope = (array) $result;
            $this->assertSame(200, $envelope['status']);
            $this->assertSame('hello-from-test-server', $envelope['body']);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_request_truncates_oversized_response(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer(
            '<?php echo str_repeat("X", 9000);'
        );
        try {
            $result = $this->invokePrivate('request', [
                "http://127.0.0.1:{$port}/index.php",
                'GET',
                '',
                [],
            ]);
            $envelope = (array) $result;
            $this->assertStringStartsWith(str_repeat('X', 100), $envelope['body']);
            $this->assertStringContainsString('[Response truncated at 8192 bytes]', $envelope['body']);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_request_sends_post_body_to_server(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer(
            '<?php echo "POST-BODY:" . file_get_contents("php://input");'
        );
        try {
            $result = $this->invokePrivate('request', [
                "http://127.0.0.1:{$port}/index.php",
                'POST',
                'payload-data',
                [],
            ]);
            $envelope = (array) $result;
            $this->assertSame('POST-BODY:payload-data', $envelope['body']);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_request_passes_custom_headers_to_server(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer(
            '<?php echo "HEADER:" . ($_SERVER["HTTP_X_PHPCLAW_TEST"] ?? "missing");'
        );
        try {
            $result = $this->invokePrivate('request', [
                "http://127.0.0.1:{$port}/index.php",
                'GET',
                '',
                ['X-PhpClaw-Test' => 'flavour-pickle'],
            ]);
            $envelope = (array) $result;
            $this->assertSame('HEADER:flavour-pickle', $envelope['body']);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_request_envelope_includes_status_code(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer(
            '<?php http_response_code(404); echo "not-found-body";'
        );
        try {
            $result = $this->invokePrivate('request', [
                "http://127.0.0.1:{$port}/index.php",
                'GET',
                '',
                [],
            ]);
            $envelope = (array) $result;
            $this->assertSame(404, $envelope['status']);
            $this->assertSame('not-found-body', $envelope['body']);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_request_envelope_includes_response_headers(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer(
            '<?php header("X-Test-Response: server-value"); echo "ok";'
        );
        try {
            $result = $this->invokePrivate('request', [
                "http://127.0.0.1:{$port}/index.php",
                'GET',
                '',
                [],
            ]);
            $envelope = (array) $result;
            $this->assertArrayHasKey('X-Test-Response', $envelope['headers']);
            $this->assertSame('server-value', $envelope['headers']['X-Test-Response']);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_request_throws_tool_exception_on_unreachable_host(): void
    {
        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/HTTP request failed/');
        $this->invokePrivate('request', ['http://127.0.0.1:1/', 'GET', '', []]);
    }

    private function spawnLocalServer(string $indexPhp): array
    {
        $docRoot = sys_get_temp_dir().'/phpclaw-httptool-test-'.uniqid('', true);
        mkdir($docRoot, 0700, true);
        file_put_contents($docRoot.'/index.php', $indexPhp);

        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);

        $cmd = sprintf(
            'php -S 127.0.0.1:%d -t %s > /dev/null 2>&1 & echo $!',
            $port,
            escapeshellarg($docRoot),
        );
        $pid = (int) trim((string) shell_exec($cmd));

        for ($i = 0; $i < 50; $i++) {
            $fp = @fsockopen('127.0.0.1', $port, $_e, $_em, 0.1);
            if ($fp) {
                fclose($fp);

                return [$pid, $port, $docRoot];
            }
            usleep(20_000);
        }
        $this->killLocalServer($pid, $docRoot);
        $this->fail("Local test server failed to start on port {$port}");
    }

    private function killLocalServer(int $pid, string $docRoot): void
    {
        if ($pid > 0) {
            @posix_kill($pid, SIGTERM);
            for ($i = 0; $i < 10; $i++) {
                if (! posix_kill($pid, 0)) {
                    break;
                }
                usleep(20_000);
            }
            @posix_kill($pid, SIGKILL);
        }
        if (is_dir($docRoot)) {
            @unlink($docRoot.'/index.php');
            @rmdir($docRoot);
        }
    }

    public function test_ip_literal_passes_validation_but_pin_entry_returns_null(): void
    {
        $resolved = SsrfValidator::resolveValidated('http://8.8.8.8/');
        $this->assertSame('8.8.8.8', $resolved['host']);
        $this->assertSame(['8.8.8.8'], $resolved['ips']);

        $this->assertNull(SsrfValidator::pinEntry($resolved));
    }

    public function test_build_curl_options_sets_curlopt_resolve_and_curlopt_url_is_hostname(): void
    {
        $resolved = ['host' => 'example.com', 'port' => 80, 'ips' => ['93.184.216.34']];
        /** @var array<int, mixed> $opts */
        $opts = $this->invokePrivate('buildCurlOptions', ['http://example.com/path', [], $resolved]);

        $this->assertSame('http://example.com/path', $opts[CURLOPT_URL]);

        $this->assertArrayHasKey(CURLOPT_RESOLVE, $opts);
        $this->assertCount(1, $opts[CURLOPT_RESOLVE]);
        $this->assertSame('example.com:80:93.184.216.34', $opts[CURLOPT_RESOLVE][0]);

        $this->assertFalse($opts[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(0, $opts[CURLOPT_MAXREDIRS]);
    }

    public function test_build_curl_options_omits_curlopt_resolve_for_ip_literal_host(): void
    {
        $resolved = ['host' => '8.8.8.8', 'port' => 80, 'ips' => ['8.8.8.8']];
        /** @var array<int, mixed> $opts */
        $opts = $this->invokePrivate('buildCurlOptions', ['http://8.8.8.8/', [], $resolved]);

        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $opts);
        $this->assertSame('http://8.8.8.8/', $opts[CURLOPT_URL]);
    }

    public function test_build_curl_options_omits_curlopt_resolve_when_resolved_is_null(): void
    {
        /** @var array<int, mixed> $opts */
        $opts = $this->invokePrivate('buildCurlOptions', ['http://example.com/', [], null]);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $opts);
    }

    public function test_live_curlopt_resolve_pin_routes_request_to_pinned_ip(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer('<?php echo "pinned-ok";');
        try {
            $pin = SsrfValidator::pinEntry([
                'host' => 'fake-phpclawtest.invalid',
                'port' => $port,
                'ips' => ['127.0.0.1'],
            ]);

            $this->assertNotNull($pin);
            $this->assertSame("fake-phpclawtest.invalid:{$port}:127.0.0.1", $pin);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "http://fake-phpclawtest.invalid:{$port}/index.php",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RESOLVE => [$pin],
            ]);
            $result = curl_exec($ch);
            curl_close($ch);

            $this->assertSame('pinned-ok', $result);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    private function invokePrivate(string $method, array $args, ?HttpTool $tool = null): mixed
    {
        $tool ??= $this->tool;
        $ref = new \ReflectionMethod(HttpTool::class, $method);

        return $ref->invokeArgs($tool, $args);
    }
}
