<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Http;

use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Http\RawHttpClient;
use PHPUnit\Framework\TestCase;

final class RawHttpClientTest extends TestCase
{
    public function test_default_timeouts_from_constants(): void
    {
        $client = new RawHttpClient;

        self::assertGreaterThan(0, $client->timeout());
        self::assertGreaterThan(0, $client->connectTimeout());
    }

    public function test_custom_timeouts_override_defaults(): void
    {
        $client = new RawHttpClient(timeout: 45, connectTimeout: 7);

        self::assertSame(45, $client->timeout());
        self::assertSame(7, $client->connectTimeout());
    }

    public function test_post_returns_decoded_json_on_2xx(): void
    {
        $client = new RawHttpClient(timeout: 5, connectTimeout: 2);

        [$pid, $port, $docRoot] = $this->spawnLocalServer(<<<'PHP'
<?php
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'echo' => json_decode(file_get_contents('php://input'), true)]);
PHP);

        try {
            $result = $client->post("http://127.0.0.1:{$port}/index.php", [], ['hello' => 'world']);

            self::assertTrue($result['ok']);
            self::assertSame(['hello' => 'world'], $result['echo']);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_post_throws_provider_exception_on_4xx_with_message_extraction(): void
    {
        $client = new RawHttpClient(timeout: 5, connectTimeout: 2);

        [$pid, $port, $docRoot] = $this->spawnLocalServer(<<<'PHP'
<?php
http_response_code(401);
header('Content-Type: application/json');
echo json_encode(['error' => ['message' => 'Invalid API key']]);
PHP);

        try {
            $this->expectException(ProviderException::class);
            $this->expectExceptionMessage('HTTP 401');

            $client->post("http://127.0.0.1:{$port}/index.php", [], []);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_post_throws_provider_exception_on_5xx(): void
    {
        $client = new RawHttpClient(timeout: 5, connectTimeout: 2);

        [$pid, $port, $docRoot] = $this->spawnLocalServer(<<<'PHP'
<?php
http_response_code(503);
header('Content-Type: application/json');
echo json_encode(['error' => 'overloaded']);
PHP);

        try {
            $this->expectException(ProviderException::class);
            $this->expectExceptionMessage('HTTP 503');

            $client->post("http://127.0.0.1:{$port}/index.php", [], []);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_post_throws_provider_exception_on_unreachable_host(): void
    {
        $client = new RawHttpClient(timeout: 2, connectTimeout: 1);

        $this->expectException(ProviderException::class);

        $client->post('http://127.0.0.1:1/', [], []);
    }

    public function test_post_sends_request_headers_to_server(): void
    {
        $client = new RawHttpClient(timeout: 5, connectTimeout: 2);

        [$pid, $port, $docRoot] = $this->spawnLocalServer(<<<'PHP'
<?php
header('Content-Type: application/json');
echo json_encode([
    'received_auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'received_xkey' => $_SERVER['HTTP_X_API_KEY'] ?? null,
]);
PHP);

        try {
            $result = $client->post(
                "http://127.0.0.1:{$port}/index.php",
                ['Authorization' => 'Bearer t0k3n', 'X-Api-Key' => 'abc'],
                [],
            );

            self::assertSame('Bearer t0k3n', $result['received_auth']);
            self::assertSame('abc', $result['received_xkey']);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_stream_calls_on_chunk_per_buffered_line(): void
    {
        $client = new RawHttpClient(timeout: 5, connectTimeout: 2);

        [$pid, $port, $docRoot] = $this->spawnLocalServer(<<<'PHP'
<?php
header('Content-Type: text/event-stream');
echo "data: one\n";
echo "data: two\n";
echo "data: three\n";
PHP);

        try {
            $chunks = [];
            $client->stream(
                "http://127.0.0.1:{$port}/index.php",
                [],
                [],
                static function (string $line) use (&$chunks): void {
                    $chunks[] = trim($line);
                },
            );

            self::assertContains('data: one', $chunks);
            self::assertContains('data: two', $chunks);
            self::assertContains('data: three', $chunks);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_stream_throws_on_unreachable_host(): void
    {
        $client = new RawHttpClient(timeout: 2, connectTimeout: 1);

        $this->expectException(ProviderException::class);

        $client->stream('http://127.0.0.1:1/', [], [], static fn () => null);
    }

    private function spawnLocalServer(string $indexPhp): array
    {
        $docRoot = sys_get_temp_dir().'/phpclaw-rawhttp-test-'.uniqid('', true);
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
}
