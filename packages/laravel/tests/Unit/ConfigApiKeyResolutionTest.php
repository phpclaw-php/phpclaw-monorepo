<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class ConfigApiKeyResolutionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    private array $original = [];

    private const VARS = [
        'ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GROQ_API_KEY',
        'GEMINI_API_KEY', 'MISTRAL_API_KEY', 'DEEPSEEK_API_KEY',
    ];

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

    public function test_an_empty_earlier_key_does_not_mask_a_populated_later_key(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = '';
        $_ENV['OPENAI_API_KEY'] = 'sk-openai-value';

        self::assertSame('sk-openai-value', $this->resolvedApiKey());
    }

    public function test_the_first_populated_key_wins(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-anthropic-value';
        $_ENV['OPENAI_API_KEY'] = 'sk-openai-value';

        self::assertSame('sk-anthropic-value', $this->resolvedApiKey());
    }

    public function test_no_keys_set_resolves_to_an_empty_string(): void
    {
        self::assertSame('', $this->resolvedApiKey());
    }

    private function resolvedApiKey(): string
    {
        $config = require __DIR__.'/../../config/phpclaw.php';

        return (string) $config['api_key'];
    }
}
