<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Skills;

use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Skills\RemoteSkillLoader;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RemoteSkillLoaderTest extends TestCase
{
    private string $cacheDir = '';

    private string $originalCacheDir = '';

    protected function setUp(): void
    {
        SkillRegistry::reset();
        HookRegistry::reset();
        $this->cacheDir = sys_get_temp_dir().'/phpclaw-rsl-test-'.uniqid('', true);
        mkdir($this->cacheDir, 0700, true);
        $this->originalCacheDir = $_ENV['PHPCLAW_CACHE_DIR'] ?? '';
        $_ENV['PHPCLAW_CACHE_DIR'] = $this->cacheDir;
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
        HookRegistry::reset();
        if ($this->originalCacheDir !== '') {
            $_ENV['PHPCLAW_CACHE_DIR'] = $this->originalCacheDir;
        } else {
            unset($_ENV['PHPCLAW_CACHE_DIR']);
        }
        $this->removeDir($this->cacheDir);
    }

    private function invoke(string $method, mixed ...$args): int
    {
        return (int) (new ReflectionMethod(RemoteSkillLoader::class, $method))->invoke(null, ...$args);
    }

    public function test_json_collection_registers_all_valid_skills(): void
    {
        $json = json_encode([
            'collection' => 'demo',
            'skills' => [
                ['name' => 'alpha', 'description' => 'a', 'tags' => ['x'], 'content' => 'ALPHA'],
                ['name' => 'beta',  'description' => 'b', 'tags' => ['y'], 'content' => 'BETA'],
                ['name' => 'skip'],
            ],
        ]);

        $loaded = $this->invoke('loadFromJson', $json);

        self::assertSame(2, $loaded);
        self::assertTrue(SkillRegistry::has('alpha'));
        self::assertTrue(SkillRegistry::has('beta'));
        self::assertFalse(SkillRegistry::has('skip'));
    }

    public function test_markdown_registers_one_skill_named_from_url(): void
    {
        $md = "# Deploy Guide\n\n## Steps\n\nRun **composer install** then deploy.";

        $loaded = $this->invoke('loadFromMarkdown', 'https://example.com/skills/deploy-guide.md', $md);

        self::assertSame(1, $loaded);
        self::assertSame(1, SkillRegistry::count());
        $skill = SkillRegistry::all()[0];
        self::assertStringContainsString('Deploy Guide', $skill->description());
        self::assertSame($md, $skill->content());
    }

    public function test_invalid_json_registers_nothing(): void
    {
        self::assertSame(0, $this->invoke('loadFromJson', '{"not":"a collection"}'));
        self::assertSame(0, SkillRegistry::count());
    }

    public function test_non_https_url_is_skipped(): void
    {
        self::assertSame(0, RemoteSkillLoader::load('http://example.com/skills.json'));
    }

    public function test_ssrf_blocked_host_is_skipped(): void
    {
        self::assertSame(0, RemoteSkillLoader::load('https://127.0.0.1/skills.json'));
    }

    public function test_markdown_skill_from_hyphenated_url_is_actually_matchable(): void
    {
        $md = "# /html-everything\n\n## Step 1: Resolve input\n\nDo the thing.\n\n## Step 2, Generate HTML\n\nOutput it.";

        $this->invoke('loadFromMarkdown', 'https://example.com/skills/html-everything/SKILL.md', $md);

        self::assertTrue(SkillRegistry::has('html_everything'));

        $matched = array_map(
            static fn ($s) => $s->name(),
            SkillRegistry::match('Convert this into HTML please'),
        );

        self::assertSame(['html_everything'], $matched, 'A registered remote skill must actually match on plain user phrasing, not just exist in the registry.');
    }

    public function test_markdown_skills_with_generic_filename_do_not_collide_on_name(): void
    {
        $md1 = "# First Skill\n\nContent one.";
        $md2 = "# Second Skill\n\nContent two.";

        $this->invoke('loadFromMarkdown', 'https://example.com/skills/first-skill/SKILL.md', $md1);
        $this->invoke('loadFromMarkdown', 'https://example.com/skills/second-skill/SKILL.md', $md2);

        self::assertSame(2, SkillRegistry::count());
        self::assertTrue(SkillRegistry::has('first_skill'));
        self::assertTrue(SkillRegistry::has('second_skill'));
    }

    public function test_markdown_skill_falls_back_to_hash_when_url_has_no_usable_slug(): void
    {
        $this->invoke('loadFromMarkdown', 'https://example.com/SKILL.md', '# Untitled');

        self::assertSame(1, SkillRegistry::count());
        $name = SkillRegistry::all()[0]->name();
        self::assertNotSame('', $name);
        self::assertStringStartsWith('remote_skill_', $name);
    }

    public function test_json_skill_with_hyphenated_tag_is_actually_matchable(): void
    {
        $json = json_encode([
            'skills' => [
                ['name' => 'json-skill', 'description' => 'demo', 'tags' => ['self-contained'], 'content' => 'CONTENT'],
            ],
        ]);

        $this->invoke('loadFromJson', $json);

        $matched = array_map(
            static fn ($s) => $s->name(),
            SkillRegistry::match('is this self hosted or contained'),
        );

        self::assertSame(['json-skill'], $matched);
    }

    public function test_build_curl_options_sets_curlopt_resolve_and_url_stays_hostname(): void
    {
        $resolved = ['host' => 'skills.example.com', 'port' => 443, 'ips' => ['93.184.216.34']];
        $opts = RemoteSkillLoader::buildCurlOptions('https://skills.example.com/skills.json', $resolved);

        $this->assertSame('https://skills.example.com/skills.json', $opts[CURLOPT_URL]);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $opts);
        $this->assertSame(['skills.example.com:443:93.184.216.34'], $opts[CURLOPT_RESOLVE]);
        $this->assertFalse($opts[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(0, $opts[CURLOPT_MAXREDIRS]);
    }

    public function test_build_curl_options_omits_curlopt_resolve_for_ip_literal(): void
    {
        $resolved = ['host' => '8.8.8.8', 'port' => 443, 'ips' => ['8.8.8.8']];
        $opts = RemoteSkillLoader::buildCurlOptions('https://8.8.8.8/skills.json', $resolved);

        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $opts);
        $this->assertSame('https://8.8.8.8/skills.json', $opts[CURLOPT_URL]);
    }

    public function test_build_curl_options_enforces_ssl_verification(): void
    {
        $resolved = ['host' => 'skills.example.com', 'port' => 443, 'ips' => ['93.184.216.34']];
        $opts = RemoteSkillLoader::buildCurlOptions('https://skills.example.com/skills.json', $resolved);

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
            $cacheFile = $this->cacheDir.'/phpclaw-skill-cache/'.md5($url).'.cache';
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
            $cacheFile = $this->cacheDir.'/phpclaw-skill-cache/'.md5($url).'.cache';
            $this->assertFileDoesNotExist($cacheFile);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_oversized_response_returns_null_and_cache_not_written(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer('<?php echo str_repeat("A", 524289);');
        try {
            $url = "http://127.0.0.1:{$port}/index.php";
            $resolved = ['host' => '127.0.0.1', 'port' => $port, 'ips' => ['127.0.0.1']];

            $result = $this->invokeFetchCached($url, $resolved);

            $this->assertNull($result);
            $cacheFile = $this->cacheDir.'/phpclaw-skill-cache/'.md5($url).'.cache';
            $this->assertFileDoesNotExist($cacheFile);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_success_response_body_returned_and_cached_with_mode_0600(): void
    {
        [$pid, $port, $docRoot] = $this->spawnLocalServer('<?php echo "hello-remote-skill";');
        try {
            $url = "http://127.0.0.1:{$port}/index.php";
            $resolved = ['host' => '127.0.0.1', 'port' => $port, 'ips' => ['127.0.0.1']];

            $result = $this->invokeFetchCached($url, $resolved);

            $this->assertSame('hello-remote-skill', $result);

            $cacheFile = $this->cacheDir.'/phpclaw-skill-cache/'.md5($url).'.cache';
            $this->assertFileExists($cacheFile);
            $this->assertSame(0600, fileperms($cacheFile) & 0777);
        } finally {
            $this->killLocalServer($pid, $docRoot);
        }
    }

    public function test_blocked_ip_literal_url_returns_null_via_validation(): void
    {
        $this->assertSame(0, RemoteSkillLoader::load('https://169.254.169.254/skills.json'));
    }

    public function test_public_ip_literal_skips_pin_but_validation_ran(): void
    {
        $resolved = ['host' => '8.8.8.8', 'port' => 443, 'ips' => ['8.8.8.8']];
        $opts = RemoteSkillLoader::buildCurlOptions('https://8.8.8.8/skills.json', $resolved);

        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $opts);
        $this->assertSame('https://8.8.8.8/skills.json', $opts[CURLOPT_URL]);
    }

    private function invokeFetchCached(string $url, array $resolved): ?string
    {
        $result = (new ReflectionMethod(RemoteSkillLoader::class, 'fetchCached'))
            ->invoke(null, $url, $resolved);

        return $result === null ? null : (string) $result;
    }

    private function spawnLocalServer(string $indexPhp): array
    {
        $docRoot = sys_get_temp_dir().'/phpclaw-rsl-srv-'.uniqid('', true);
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

    public function test_markdown_yaml_header_is_read_for_the_description_and_dropped_from_the_content(): void
    {
        $md = "---\nname: header-skill\ndescription: Turns notes into pages\nallowed-tools: Bash, Read\n---\n# Header Skill\n\nBody line one.";

        $this->invoke('loadFromMarkdown', 'https://example.com/skills/header-skill/SKILL.md', $md);

        $skill = SkillRegistry::all()[0];
        self::assertStringStartsWith('# Header Skill', $skill->content());
        self::assertStringNotContainsString('allowed-tools', $skill->content());
        self::assertStringContainsString('Turns notes into pages', $skill->description());
    }

    public function test_markdown_without_a_header_keeps_its_content_unchanged(): void
    {
        $md = "# Plain Skill\n\nBody line one.";

        $this->invoke('loadFromMarkdown', 'https://example.com/skills/plain-skill/SKILL.md', $md);

        self::assertSame($md, SkillRegistry::all()[0]->content());
    }

    public function test_json_collection_content_is_registered_unchanged_even_when_it_starts_with_a_header(): void
    {
        $content = "---\nnote: kept\n---\nJSON body.";
        $json = json_encode([
            'collection' => 'demo',
            'skills' => [
                ['name' => 'gamma', 'description' => 'g', 'tags' => ['z'], 'content' => $content],
            ],
        ]);

        $this->invoke('loadFromJson', $json);

        self::assertSame($content, SkillRegistry::all()[0]->content());
    }
}
