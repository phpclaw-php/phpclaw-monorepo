<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Feature;

use PhpClaw\Skills\RemoteSkillLoader;
use PhpClaw\Tools\RemoteToolActivator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RemoteFetchIntegrationTest extends TestCase
{
    private static string $docroot = '';

    private static string $base = '';

    /** @var resource|null */
    private static $server;

    public static function setUpBeforeClass(): void
    {
        self::$docroot = sys_get_temp_dir().'/phpclaw-httpfix-'.getmypid();
        @mkdir(self::$docroot, 0755, true);

        file_put_contents(self::$docroot.'/skills.json', json_encode([
            'skills' => [['name' => 'remote_alpha', 'description' => 'd', 'tags' => ['t'], 'content' => 'ALPHA-BODY']],
        ]));
        file_put_contents(self::$docroot.'/profile.json', json_encode([
            'profile' => 'store', 'tools' => ['product_tool', 'order_tool'], 'max_tools_per_turn' => 6,
        ]));
        file_put_contents(self::$docroot.'/huge.json', str_repeat('x', 600000));

        $port = self::freePort();
        self::$base = "http://127.0.0.1:{$port}";

        self::$server = proc_open(
            ['php', '-S', "127.0.0.1:{$port}", '-t', self::$docroot],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        for ($i = 0; $i < 50; $i++) {
            if (@file_get_contents(self::$base.'/skills.json') !== false) {
                return;
            }
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        foreach ((array) glob(self::$docroot.'/*') as $f) {
            if (is_string($f)) {
                @unlink($f);
            }
        }
        @rmdir(self::$docroot);
        self::clearCaches();
    }

    protected function setUp(): void
    {
        self::clearCaches();
        if (@file_get_contents(self::$base.'/skills.json') === false) {
            self::markTestSkipped('local php -S server unavailable');
        }
    }

    private function fetch(string $class, string $url): ?string
    {
        $parsed = parse_url($url);
        $host = (string) ($parsed['host'] ?? '127.0.0.1');
        $resolved = [
            'host' => $host,
            'port' => (int) ($parsed['port'] ?? ($parsed['scheme'] === 'https' ? 443 : 80)),
            'ips' => [$host],
        ];

        return (new ReflectionMethod($class, 'fetchCached'))->invoke(null, $url, $resolved);
    }

    public function test_skill_loader_fetches_body_over_http(): void
    {
        $body = $this->fetch(RemoteSkillLoader::class, self::$base.'/skills.json');

        self::assertNotNull($body);
        self::assertStringContainsString('ALPHA-BODY', (string) $body);
    }

    public function test_skill_loader_writes_and_reuses_cache(): void
    {
        $url = self::$base.'/skills.json';
        $this->fetch(RemoteSkillLoader::class, $url);

        $cacheFile = '/tmp/phpclaw-skill-cache/'.md5($url).'.cache';
        self::assertFileExists($cacheFile);

        file_put_contents($cacheFile, 'CACHED-SENTINEL');
        $cached = $this->fetch(RemoteSkillLoader::class, $url);

        self::assertSame('CACHED-SENTINEL', $cached, 'second fetch must read the fresh cache, not the server');
    }

    public function test_tool_activator_fetches_profile_over_http(): void
    {
        $body = $this->fetch(RemoteToolActivator::class, self::$base.'/profile.json');

        self::assertNotNull($body);
        self::assertStringContainsString('product_tool', (string) $body);
    }

    public function test_oversized_response_is_rejected(): void
    {
        self::assertNull($this->fetch(RemoteSkillLoader::class, self::$base.'/huge.json'));
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function clearCaches(): void
    {
        foreach (['/tmp/phpclaw-skill-cache', '/tmp/phpclaw-tool-profiles'] as $dir) {
            foreach ((array) glob($dir.'/*') as $f) {
                if (is_string($f)) {
                    @unlink($f);
                }
            }
        }
    }
}
