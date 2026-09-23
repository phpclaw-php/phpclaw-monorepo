<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools;

use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\RemoteToolActivator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RemoteToolActivatorTest extends TestCase
{
    private string $cacheDir = '';

    private string $originalCacheDir = '';

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/phpclaw-rta-test-'.uniqid('', true);
        mkdir($this->cacheDir, 0700, true);
        $this->originalCacheDir = $_ENV['PHPCLAW_CACHE_DIR'] ?? '';
        $_ENV['PHPCLAW_CACHE_DIR'] = $this->cacheDir;
    }

    protected function tearDown(): void
    {
        if ($this->originalCacheDir !== '') {
            $_ENV['PHPCLAW_CACHE_DIR'] = $this->originalCacheDir;
        } else {
            unset($_ENV['PHPCLAW_CACHE_DIR']);
        }
        $this->removeDir($this->cacheDir);
    }

    private function tools(string ...$names): array
    {
        return array_map(
            static fn (string $n): ToolInterface => new class($n) implements ToolInterface
            {
                public function __construct(private string $n) {}

                public function name(): string
                {
                    return $this->n;
                }

                public function description(): string
                {
                    return $this->n;
                }

                public function inputSchema(): array
                {
                    return ['type' => 'object', 'properties' => []];
                }

                public function execute(array $input): string
                {
                    return '';
                }
            },
            $names,
        );
    }

    public function test_filter_keeps_only_profile_tools(): void
    {
        $all = $this->tools('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j');
        $kept = RemoteToolActivator::filter($all, ['a', 'e', 'j']);
        $names = array_map(static fn (ToolInterface $t): string => $t->name(), $kept);

        self::assertCount(3, $kept);
        self::assertSame(['a', 'e', 'j'], $names);
    }

    public function test_empty_allowlist_imposes_no_restriction(): void
    {
        $all = $this->tools('a', 'b', 'c');

        self::assertCount(3, RemoteToolActivator::filter($all, []));
    }

    public function test_unknown_tool_names_are_ignored(): void
    {
        $all = $this->tools('a', 'b');

        self::assertCount(1, RemoteToolActivator::filter($all, ['a', 'ghost']));
    }

    public function test_non_https_url_returns_null(): void
    {
        self::assertNull(RemoteToolActivator::fetch('http://example.com/profile.json'));
    }

    public function test_ssrf_blocked_host_returns_null(): void
    {
        self::assertNull(RemoteToolActivator::fetch('https://localhost/profile.json'));
    }

    public function test_build_curl_options_sets_curlopt_resolve_and_url_stays_hostname(): void
    {
        $resolved = ['host' => 'example.com', 'port' => 443, 'ips' => ['93.184.216.34']];
        $opts = RemoteToolActivator::buildCurlOptions('https://example.com/profile.json', $resolved);

        $this->assertSame('https://example.com/profile.json', $opts[CURLOPT_URL]);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $opts);
        $this->assertSame(['example.com:443:93.184.216.34'], $opts[CURLOPT_RESOLVE]);
        $this->assertFalse($opts[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(0, $opts[CURLOPT_MAXREDIRS]);
    }

    public function test_build_curl_options_omits_curlopt_resolve_for_ip_literal(): void
    {
        $resolved = ['host' => '8.8.8.8', 'port' => 443, 'ips' => ['8.8.8.8']];
        $opts = RemoteToolActivator::buildCurlOptions('https://8.8.8.8/profile.json', $resolved);

        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $opts);
        $this->assertSame('https://8.8.8.8/profile.json', $opts[CURLOPT_URL]);
    }

    public function test_build_curl_options_enforces_ssl_verification(): void
    {
        $resolved = ['host' => 'example.com', 'port' => 443, 'ips' => ['93.184.216.34']];
        $opts = RemoteToolActivator::buildCurlOptions('https://example.com/profile.json', $resolved);

        $this->assertTrue($opts[CURLOPT_SSL_VERIFYPEER]);
        $this->assertSame(2, $opts[CURLOPT_SSL_VERIFYHOST]);
    }

    public function test_4xx_response_returns_null_and_cache_not_written(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer('<?php http_response_code(404); echo "Not Found";');
        try {
            $url = "http://127.0.0.1:{$port}/index.php";
            $resolved = ['host' => '127.0.0.1', 'port' => $port, 'ips' => ['127.0.0.1']];

            $result = $this->invokeFetchCached($url, $resolved);

            $this->assertNull($result);
            $cacheFile = $this->cacheDir.'/phpclaw-tool-profiles/'.md5($url).'.json';
            $this->assertFileDoesNotExist($cacheFile);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_5xx_response_returns_null_and_cache_not_written(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer('<?php http_response_code(500); echo "Server Error";');
        try {
            $url = "http://127.0.0.1:{$port}/index.php";
            $resolved = ['host' => '127.0.0.1', 'port' => $port, 'ips' => ['127.0.0.1']];

            $result = $this->invokeFetchCached($url, $resolved);

            $this->assertNull($result);
            $cacheFile = $this->cacheDir.'/phpclaw-tool-profiles/'.md5($url).'.json';
            $this->assertFileDoesNotExist($cacheFile);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_oversized_response_returns_null_and_cache_not_written(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer('<?php echo str_repeat("A", 65537);');
        try {
            $url = "http://127.0.0.1:{$port}/index.php";
            $resolved = ['host' => '127.0.0.1', 'port' => $port, 'ips' => ['127.0.0.1']];

            $result = $this->invokeFetchCached($url, $resolved);

            $this->assertNull($result);
            $cacheFile = $this->cacheDir.'/phpclaw-tool-profiles/'.md5($url).'.json';
            $this->assertFileDoesNotExist($cacheFile);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_success_response_body_returned_and_cached_with_mode_0600(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer('<?php echo "hello-tool-profile";');
        try {
            $url = "http://127.0.0.1:{$port}/index.php";
            $resolved = ['host' => '127.0.0.1', 'port' => $port, 'ips' => ['127.0.0.1']];

            $result = $this->invokeFetchCached($url, $resolved);

            $this->assertSame('hello-tool-profile', $result);

            $cacheFile = $this->cacheDir.'/phpclaw-tool-profiles/'.md5($url).'.json';
            $this->assertFileExists($cacheFile);
            $this->assertSame(0600, fileperms($cacheFile) & 0777);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_blocked_ip_literal_url_returns_null_via_validation(): void
    {
        $this->assertNull(RemoteToolActivator::fetch('https://169.254.169.254/metadata'));
    }

    public function test_public_ip_literal_skips_pin_but_validation_ran(): void
    {
        $resolved = ['host' => '8.8.8.8', 'port' => 443, 'ips' => ['8.8.8.8']];
        $opts = RemoteToolActivator::buildCurlOptions('https://8.8.8.8/profile.json', $resolved);

        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $opts);
        $this->assertSame('https://8.8.8.8/profile.json', $opts[CURLOPT_URL]);
    }

    private function invokeFetchCached(string $url, array $resolved): ?string
    {
        $result = (new ReflectionMethod(RemoteToolActivator::class, 'fetchCached'))
            ->invoke(null, $url, $resolved);

        return $result === null ? null : (string) $result;
    }

    private function spawnLocalServer(string $indexPhp): array
    {
        $docRoot = sys_get_temp_dir().'/phpclaw-rta-srv-'.uniqid('', true);
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

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/*') as $item) {
            if (is_string($item) && is_dir($item)) {
                $this->removeDir($item);
            } elseif (is_string($item)) {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }
}
