<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use Orchestra\Testbench\TestCase;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\Laravel\Facades\PhpClaw as PhpClawFacade;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class LaravelIntegrationFakeTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    public function test_facade_send_returns_a_response(): void
    {
        PhpClawFacade::fake(['PONG']);

        $response = PhpClawFacade::send('Reply with one word: PONG');

        self::assertSame('PONG', $response->text);
    }

    public function test_the_facade_records_what_was_sent(): void
    {
        $fake = PhpClawFacade::fake(['PONG']);

        PhpClawFacade::send('Reply with one word: PONG');

        $fake->assertSent('Reply with one word: PONG');
    }

    public function test_conversation_carries_context_across_turns(): void
    {
        PhpClawFacade::fake(['noted', 'BANANA']);

        $agent = $this->app->make(PhpClaw::class);
        $conv = $agent->conversation();

        $turn1 = $agent->sendInConversation($conv, 'My secret word is BANANA.');
        $turn2 = $agent->sendInConversation($turn1->conversation, 'What was my secret word?');

        self::assertStringContainsStringIgnoringCase('banana', $turn2->response->text);
        self::assertNotSame($turn1->conversation->id, '');
        self::assertSame($turn1->conversation->id, $turn2->conversation->id);
    }

    public function test_stream_assembles_the_full_response_from_its_tokens(): void
    {
        PhpClawFacade::fake(['PING']);

        $tokens = [];
        $response = $this->app->make(PhpClaw::class)->stream(
            message: 'Reply with one word: PING',
            onToken: function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            },
        );

        self::assertNotEmpty($tokens);
        self::assertSame(implode('', $tokens), $response->text);
    }

    public function test_the_artisan_command_exits_zero(): void
    {
        PhpClawFacade::fake(['OK']);

        $this->artisan('phpclaw', ['message' => 'Reply with one word: OK'])
            ->assertExitCode(0);
    }
}
