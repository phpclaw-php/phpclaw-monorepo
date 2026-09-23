<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\WordPress\Admin\SiteHealth;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SiteHealth::class)]
final class SiteHealthTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'esc_html__' => static fn (string $s, string $d = ''): string => $s,
            'esc_url' => static fn (string $s): string => $s,
            'esc_html' => static fn (string $s): string => $s,
            'admin_url' => static fn (string $path): string => 'http://example.com/wp-admin/'.ltrim($path, '/'),
            '__' => static fn (string $s, string $d = ''): string => $s,
        ]);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_register_adds_site_status_tests_filter(): void
    {
        Functions\expect('add_filter')
            ->once()
            ->with('site_status_tests', [SiteHealth::class, 'addTests']);

        SiteHealth::register();
    }

    public function test_add_tests_inserts_phpclaw_connection(): void
    {
        $tests = SiteHealth::addTests([]);

        self::assertArrayHasKey('direct', $tests);
        self::assertArrayHasKey('phpclaw_connection', $tests['direct']);
        self::assertSame([SiteHealth::class, 'testConnection'], $tests['direct']['phpclaw_connection']['test']);
        self::assertSame('phpClaw AI Engine', $tests['direct']['phpclaw_connection']['label']);
    }

    public function test_add_tests_preserves_existing_tests(): void
    {
        $original = ['direct' => ['existing' => ['label' => 'Other']]];
        $tests = SiteHealth::addTests($original);

        self::assertArrayHasKey('existing', $tests['direct']);
        self::assertArrayHasKey('phpclaw_connection', $tests['direct']);
    }

    public function test_test_connection_returns_recommended_when_not_configured(): void
    {
        Functions\expect('get_option')->once()->with('phpclaw_settings', [])->andReturn([]);

        $result = SiteHealth::testConnection();

        self::assertSame('recommended', $result['status']);
        self::assertSame('orange', $result['badge']['color']);
        self::assertStringContainsString('not configured', $result['label']);
        self::assertStringContainsString('Configure', $result['description']);
    }

    public function test_test_connection_returns_recommended_when_no_api_key_and_not_ollama(): void
    {
        Functions\expect('get_option')->once()->andReturn([
            'provider' => 'openai',
            'api_key' => '',
        ]);

        $result = SiteHealth::testConnection();

        self::assertSame('recommended', $result['status']);
    }

    public function test_test_connection_returns_good_when_ollama_without_api_key(): void
    {
        Functions\expect('get_option')->once()->andReturn([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'api_key' => '',
        ]);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $this->injectPluginWithEngine($engine);

        $result = SiteHealth::testConnection();

        self::assertSame('good', $result['status']);
        self::assertSame('green', $result['badge']['color']);
        self::assertStringContainsString('ollama', $result['description']);
        self::assertStringContainsString('qwen2.5:7b', $result['description']);
    }

    public function test_test_connection_returns_good_with_anthropic_api_key(): void
    {
        Functions\expect('get_option')->once()->andReturn([
            'provider' => 'anthropic',
            'model' => 'claude',
            'api_key' => 'sk-real',
        ]);

        $engine = \Mockery::mock(PhpClawInterface::class);
        $this->injectPluginWithEngine($engine);

        $result = SiteHealth::testConnection();

        self::assertSame('good', $result['status']);
    }

    public function test_test_connection_returns_critical_when_engine_fails(): void
    {
        Functions\expect('get_option')->once()->andReturn([
            'provider' => 'anthropic',
            'api_key' => 'sk-real',
            'model' => 'claude',
        ]);

        $this->injectPluginWithEngineThatThrows();

        $result = SiteHealth::testConnection();

        self::assertSame('critical', $result['status']);
        self::assertSame('red', $result['badge']['color']);
        self::assertStringContainsString('failed to initialise', $result['label']);
    }

    private function injectPluginWithEngine(PhpClawInterface $engine): void
    {
        $plugin = \Mockery::mock(Plugin::class);
        $plugin->allows('engine')->andReturn($engine);

        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $plugin);
    }

    private function injectPluginWithEngineThatThrows(): void
    {
        $plugin = \Mockery::mock(Plugin::class);
        $plugin->allows('engine')->andThrow(new \RuntimeException('engine init failed'));

        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setAccessible(true);
        $prop->setValue(null, $plugin);
    }
}
