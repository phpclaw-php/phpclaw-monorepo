<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Config;

use PhpClaw\Config\EnvVars;
use PhpClaw\Skills\RemoteSkillLoader;
use PhpClaw\Tools\RemoteToolActivator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CacheDirOverrideTest extends TestCase
{
    private string $original = '';

    protected function setUp(): void
    {
        $this->original = $_ENV[EnvVars::PHPCLAW_CACHE_DIR] ?? '';
        unset($_ENV[EnvVars::PHPCLAW_CACHE_DIR]);
    }

    protected function tearDown(): void
    {
        if ($this->original === '') {
            unset($_ENV[EnvVars::PHPCLAW_CACHE_DIR]);
        } else {
            $_ENV[EnvVars::PHPCLAW_CACHE_DIR] = $this->original;
        }
    }

    public function test_tool_profiles_default_to_tmp(): void
    {
        $this->assertSame('/tmp/phpclaw-tool-profiles', $this->cacheDir(RemoteToolActivator::class));
    }

    public function test_skills_default_to_tmp(): void
    {
        $this->assertSame('/tmp/phpclaw-skill-cache', $this->cacheDir(RemoteSkillLoader::class));
    }

    public function test_override_redirects_both_caches(): void
    {
        $_ENV[EnvVars::PHPCLAW_CACHE_DIR] = '/var/data/phpclaw-cache/';

        $this->assertSame('/var/data/phpclaw-cache/phpclaw-tool-profiles', $this->cacheDir(RemoteToolActivator::class));
        $this->assertSame('/var/data/phpclaw-cache/phpclaw-skill-cache', $this->cacheDir(RemoteSkillLoader::class));
    }

    public function test_empty_override_falls_back_to_tmp(): void
    {
        $_ENV[EnvVars::PHPCLAW_CACHE_DIR] = '';

        $this->assertSame('/tmp/phpclaw-tool-profiles', $this->cacheDir(RemoteToolActivator::class));
        $this->assertSame('/tmp/phpclaw-skill-cache', $this->cacheDir(RemoteSkillLoader::class));
    }

    private function cacheDir(string $class): string
    {
        $method = new ReflectionMethod($class, 'cacheDir');

        return (string) $method->invoke(null);
    }
}
