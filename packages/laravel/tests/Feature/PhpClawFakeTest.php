<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use Orchestra\Testbench\TestCase;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Laravel\Facades\PhpClaw;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Testing\ClawFake;

final class PhpClawFakeTest extends TestCase
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

    public function test_fake_replaces_the_container_binding(): void
    {
        PhpClaw::fake();

        self::assertInstanceOf(ClawFake::class, $this->app->make(ClawInterface::class));
    }

    public function test_an_injected_interface_resolves_to_the_fake(): void
    {
        $fake = PhpClaw::fake(['Injected reply.']);

        $engine = $this->app->make(ClawInterface::class);

        self::assertSame('Injected reply.', $engine->send('hello')->text);
        $fake->assertSent('hello');
    }

    public function test_queued_responses_are_returned_in_order_then_fall_back(): void
    {
        PhpClaw::fake(['first', 'second']);

        self::assertSame('first', PhpClaw::send('a')->text);
        self::assertSame('second', PhpClaw::send('b')->text);
        self::assertSame(ClawFake::DEFAULT_TEXT, PhpClaw::send('c')->text);
    }

    public function test_assert_sent_and_not_sent(): void
    {
        $fake = PhpClaw::fake();

        PhpClaw::send('count the users');

        $fake->assertSent('count the users');
        $fake->assertNotSent('drop the database');
        $fake->assertSentCount(1);
    }

    public function test_assert_sent_accepts_a_predicate(): void
    {
        $fake = PhpClaw::fake();

        PhpClaw::send('summarise this week');

        $fake->assertSent(static fn (string $m): bool => str_contains($m, 'summarise'));
    }

    public function test_assert_nothing_sent(): void
    {
        PhpClaw::fake()->assertNothingSent();
    }

    public function test_stream_emits_the_response_and_records_the_message(): void
    {
        $fake = PhpClaw::fake(['streamed text']);
        $received = '';

        $response = PhpClaw::stream('go', function (string $token) use (&$received): void {
            $received .= $token;
        });

        self::assertSame('streamed text', $received);
        self::assertSame('streamed text', $response->text);
        $fake->assertSent('go');
    }

    public function test_conversation_methods_record_and_return_turns(): void
    {
        $fake = PhpClaw::fake(['turn one']);

        $conversation = PhpClaw::conversation();
        $turn = PhpClaw::sendInConversation($conversation, 'in convo');

        self::assertSame('turn one', $turn->response->text);
        self::assertSame($conversation->id, $turn->conversation->id);
        $fake->assertSent('in convo');
    }

    public function test_sent_messages_are_exposed_in_order(): void
    {
        $fake = PhpClaw::fake();

        PhpClaw::send('one');
        PhpClaw::send('two');

        self::assertSame(['one', 'two'], $fake->sentMessages());
    }
}
