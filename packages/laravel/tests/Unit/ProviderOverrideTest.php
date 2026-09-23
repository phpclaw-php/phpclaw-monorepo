<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillRegistry;

final class ProviderOverrideTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        SkillRegistry::reset();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    private function buildOverride(): ?ProviderInterface
    {
        $method = new \ReflectionMethod(EngineFactory::class, 'buildCustomProviderOverride');
        $method->setAccessible(true);

        /** @var ProviderInterface|null $override */
        $override = $method->invoke(null);

        return $override;
    }

    public function test_non_custom_provider_resolves_without_override(): void
    {
        $this->app['config']->set('phpclaw.provider', 'anthropic');
        $this->app['config']->set('phpclaw.base_url', 'https://api.example.com/v1');

        $this->assertNull($this->buildOverride(), 'Non-custom provider must not build a base_url override.');

        $this->assertInstanceOf(PhpClawInterface::class, $this->app->make(PhpClawInterface::class));
    }

    public function test_custom_provider_with_valid_https_url_builds_override(): void
    {
        $this->app['config']->set('phpclaw.provider', 'custom');
        $this->app['config']->set('phpclaw.base_url', 'https://my-llm.example.com/v1');

        $override = $this->buildOverride();

        $this->assertInstanceOf(OpenAIProvider::class, $override);
        $this->assertSame('custom', $override->name());
        $this->assertSame('https://my-llm.example.com/v1', $override->endpoint());
    }

    public function test_custom_provider_with_valid_http_url_builds_override(): void
    {
        $this->app['config']->set('phpclaw.provider', 'custom');
        $this->app['config']->set('phpclaw.base_url', 'http://localhost:11434/v1');

        $override = $this->buildOverride();

        $this->assertInstanceOf(OpenAIProvider::class, $override);
        $this->assertSame('http://localhost:11434/v1', $override->endpoint());
    }

    public function test_custom_provider_allows_ipv6_loopback_over_plain_http(): void
    {
        $this->app['config']->set('phpclaw.provider', 'custom');
        $this->app['config']->set('phpclaw.base_url', 'http://[::1]:11434/v1');

        $override = $this->buildOverride();

        $this->assertInstanceOf(OpenAIProvider::class, $override, 'IPv6 loopback is a loopback host and must be allowed over plain HTTP.');
        $this->assertSame('http://[::1]:11434/v1', $override->endpoint());
    }

    public function test_custom_provider_rejects_non_loopback_ipv6_over_plain_http(): void
    {
        $this->app['config']->set('phpclaw.provider', 'custom');
        $this->app['config']->set('phpclaw.base_url', 'http://[2001:db8::1]/v1');

        $this->assertNull($this->buildOverride(), 'A non-loopback host over plain HTTP must be refused.');
    }

    public function test_custom_provider_with_empty_base_url_skips_override(): void
    {
        $this->app['config']->set('phpclaw.provider', 'custom');
        $this->app['config']->set('phpclaw.base_url', '');

        $this->assertNull($this->buildOverride(), 'Empty base_url must skip the override.');
    }

    public function test_custom_provider_with_invalid_scheme_skips_override(): void
    {
        $this->app['config']->set('phpclaw.provider', 'custom');
        $this->app['config']->set('phpclaw.base_url', 'ftp://evil.example.com/v1');

        $this->assertNull($this->buildOverride(), 'A non-http(s) scheme must be rejected (SSRF guard).');
    }

    public function test_custom_provider_with_javascript_scheme_skips_override(): void
    {
        $this->app['config']->set('phpclaw.provider', 'custom');
        $this->app['config']->set('phpclaw.base_url', 'javascript://evil');

        $this->assertNull($this->buildOverride(), 'A javascript: scheme must be rejected (SSRF guard).');
    }

    public function test_base_url_config_key_exists_and_is_env_backed(): void
    {
        $this->assertSame('', config('phpclaw.base_url'));
    }
}
