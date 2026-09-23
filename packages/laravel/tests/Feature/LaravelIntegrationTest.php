<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use Orchestra\Testbench\TestCase;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\Laravel\Facades\PhpClaw as PhpClawFacade;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Feature tests for the Laravel adapter, requires a real LLM API key.
 */
#[Group('feature')]
final class LaravelIntegrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set(
            'phpclaw.api_key',
            getenv('ANTHROPIC_API_KEY')
                ?: getenv('OPENAI_API_KEY')
                ?: getenv('GROQ_API_KEY')
                ?: 'test-placeholder-key',
        );
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    private static function requireApiKey(): void
    {
        $has = (getenv('ANTHROPIC_API_KEY') || getenv('OPENAI_API_KEY') || getenv('GROQ_API_KEY'));
        if (! $has) {
            self::markTestSkipped('Feature test requires ANTHROPIC_API_KEY, OPENAI_API_KEY, or GROQ_API_KEY.');
        }
    }

    #[Group('feature')]
    public function test_phpclaw_service_is_resolvable_from_container(): void
    {
        $instance = $this->app->make(PhpClaw::class);

        $this->assertInstanceOf(PhpClaw::class, $instance);
    }

    #[Group('feature')]
    public function test_facade_send_returns_response(): void
    {
        self::requireApiKey();

        $response = PhpClawFacade::send('Reply with one word: PONG');

        $this->assertNotEmpty($response->text);
        $this->assertContains($response->provider, ['anthropic', 'openai', 'groq', 'gemini']);
    }

    #[Group('feature')]
    public function test_conversation_persists_context(): void
    {
        self::requireApiKey();

        $agent = $this->app->make(PhpClaw::class);
        $conv = $agent->conversation();

        $turn1 = $agent->sendInConversation($conv, 'My secret word is BANANA.');
        $turn2 = $agent->sendInConversation($turn1->conversation, 'What was my secret word?');

        $this->assertStringContainsStringIgnoringCase('banana', $turn2->response->text);
    }

    #[Group('feature')]
    public function test_stream_assembles_full_response(): void
    {
        self::requireApiKey();

        $tokens = [];
        $response = $this->app->make(PhpClaw::class)->stream(
            message: 'Reply with one word: PING',
            onToken: function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            },
        );

        $this->assertNotEmpty($tokens);
        $this->assertSame(implode('', $tokens), $response->text);
    }

    #[Group('feature')]
    public function test_artisan_phpclaw_command_exits_zero(): void
    {
        self::requireApiKey();

        $this->artisan('phpclaw', ['message' => 'Reply with one word: OK'])
            ->assertExitCode(0);
    }
}
