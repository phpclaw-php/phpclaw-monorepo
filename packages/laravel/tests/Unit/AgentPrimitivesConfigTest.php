<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class AgentPrimitivesConfigTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    private const VARS = [
        'PHPCLAW_FALLBACK_PROVIDER', 'PHPCLAW_FALLBACK_MODEL', 'PHPCLAW_FALLBACK_API_KEY',
        'PHPCLAW_RATE_LIMIT_RPM', 'PHPCLAW_RESPONSE_CACHE', 'PHPCLAW_RESPONSE_CACHE_TTL',
        'PHPCLAW_MAX_TOKEN_BUDGET',
    ];

    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARS as $var) {
            $this->original[$var] = getenv($var) === false ? null : (string) getenv($var);
            putenv($var);
            unset($_ENV[$var], $_SERVER[$var]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $var => $value) {
            if ($value === null) {
                putenv($var);
                unset($_ENV[$var], $_SERVER[$var]);

                continue;
            }

            putenv("{$var}={$value}");
            $_ENV[$var] = $value;
        }

        parent::tearDown();
    }

    private function config(): array
    {
        return require __DIR__.'/../../config/phpclaw.php';
    }

    public function test_fallback_provider_defaults_to_empty_string(): void
    {
        self::assertSame('', $this->config()['fallback_provider']);
    }

    public function test_fallback_provider_reads_env_override(): void
    {
        $_ENV['PHPCLAW_FALLBACK_PROVIDER'] = 'openai';

        self::assertSame('openai', $this->config()['fallback_provider']);
    }

    public function test_fallback_model_defaults_to_empty_string(): void
    {
        self::assertSame('', $this->config()['fallback_model']);
    }

    public function test_fallback_model_reads_env_override(): void
    {
        $_ENV['PHPCLAW_FALLBACK_MODEL'] = 'gpt-4o-mini';

        self::assertSame('gpt-4o-mini', $this->config()['fallback_model']);
    }

    public function test_fallback_api_key_defaults_to_empty_string(): void
    {
        self::assertSame('', $this->config()['fallback_api_key']);
    }

    public function test_fallback_api_key_reads_env_override(): void
    {
        $_ENV['PHPCLAW_FALLBACK_API_KEY'] = 'sk-fallback-value';

        self::assertSame('sk-fallback-value', $this->config()['fallback_api_key']);
    }

    public function test_rate_limit_rpm_defaults_to_zero(): void
    {
        self::assertSame(0, $this->config()['rate_limit_rpm']);
    }

    public function test_rate_limit_rpm_reads_env_override(): void
    {
        $_ENV['PHPCLAW_RATE_LIMIT_RPM'] = '30';

        self::assertSame(30, $this->config()['rate_limit_rpm']);
    }

    public function test_response_cache_defaults_to_false(): void
    {
        self::assertFalse($this->config()['response_cache']);
    }

    public function test_response_cache_reads_env_override(): void
    {
        $_ENV['PHPCLAW_RESPONSE_CACHE'] = 'true';

        self::assertTrue($this->config()['response_cache']);
    }

    public function test_response_cache_ttl_defaults_to_3600(): void
    {
        self::assertSame(3600, $this->config()['response_cache_ttl']);
    }

    public function test_response_cache_ttl_reads_env_override(): void
    {
        $_ENV['PHPCLAW_RESPONSE_CACHE_TTL'] = '120';

        self::assertSame(120, $this->config()['response_cache_ttl']);
    }

    public function test_max_token_budget_defaults_to_zero(): void
    {
        self::assertSame(0, $this->config()['max_token_budget']);
    }

    public function test_max_token_budget_reads_env_override(): void
    {
        $_ENV['PHPCLAW_MAX_TOKEN_BUDGET'] = '50000';

        self::assertSame(50000, $this->config()['max_token_budget']);
    }
}
