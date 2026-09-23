<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Feature;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Claw;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ClawFeatureTest extends TestCase
{
    private const PROVIDER_KEYS = [
        'ANTHROPIC_API_KEY' => 'Anthropic',
        'OPENAI_API_KEY' => 'OpenAI',
        'GROQ_API_KEY' => 'Groq',
        'GEMINI_API_KEY' => 'Gemini',
        'MISTRAL_API_KEY' => 'Mistral',
        'OLLAMA_HOST' => 'Ollama',
    ];

    private static function requireApiKey(): void
    {
        foreach (array_keys(self::PROVIDER_KEYS) as $envVar) {
            $value = getenv($envVar);
            if ($value !== false && $value !== '') {
                return;
            }
        }

        self::markTestSkipped(
            'Feature tests require at least one provider configured. Set any of: '
            .implode(', ', array_keys(self::PROVIDER_KEYS))
            .'. Works in any PHP environment.'
        );
    }

    #[Group('feature')]
    public function test_send_returns_non_empty_text_response(): void
    {
        self::requireApiKey();

        $agent = Claw::builder()->build();
        $response = $agent->send('Reply with exactly the word: PONG');

        $this->assertInstanceOf(AgentResponse::class, $response);
        $this->assertNotEmpty($response->text);
        $this->assertIsString($response->text);
        $this->assertGreaterThan(0, $response->iterations);
    }

    #[Group('feature')]
    public function test_stream_assembles_full_response(): void
    {
        self::requireApiKey();

        $agent = Claw::builder()->build();
        $tokens = [];

        $response = $agent->stream(
            'Reply with exactly the word: PING',
            function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            }
        );

        $this->assertInstanceOf(AgentResponse::class, $response);
        $this->assertNotEmpty($response->text);
        $this->assertNotEmpty($tokens);
        $this->assertSame(implode('', $tokens), $response->text);
    }

    #[Group('feature')]
    public function test_send_provider_and_model_are_set_in_response(): void
    {
        self::requireApiKey();

        $agent = Claw::builder()->build();
        $response = $agent->send('Say hello.');

        $this->assertNotEmpty($response->provider);
        $this->assertNotEmpty($response->model);
        $this->assertContains($response->provider, ['anthropic', 'openai', 'groq', 'gemini', 'mistral', 'ollama']);
    }

    #[Group('feature')]
    public function test_send_token_counts_are_positive(): void
    {
        self::requireApiKey();

        $agent = Claw::builder()->build();
        $response = $agent->send('What is 1 + 1?');

        $this->assertGreaterThan(0, $response->inputTokens ?? 0);
        $this->assertGreaterThan(0, $response->outputTokens ?? 0);
    }
}
