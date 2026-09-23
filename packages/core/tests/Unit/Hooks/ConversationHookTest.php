<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks;

use PhpClaw\Claw;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class ConversationHookTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        SkillRegistry::reset();
    }

    private function makeSuccessProvider(string $text = 'Hello!'): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturn(['type' => 'text', 'text' => $text]);

        return $mock;
    }

    private function makeFailingProvider(): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willThrowException(new ProviderException('boom'));

        return $mock;
    }

    private function makeAgent(ProviderInterface $provider): Claw
    {
        return Claw::builder()
            ->providerOverride($provider)
            ->useDefaultGuards(false)
            ->memory(new ArrayMemory)
            ->build();
    }

    public function test_conversation_start_fires_on_first_message_and_carries_run_id(): void
    {
        $context = [];

        HookRegistry::on('conversation.start', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = $this->makeAgent($this->makeSuccessProvider());
        $conv = $agent->conversation();

        $this->assertSame([], $context);

        $agent->sendInConversation($conv, 'hello');

        $this->assertSame($conv->id, $context['conversation_id'] ?? null);
        $this->assertArrayHasKey('metadata', $context);
        $this->assertNotSame('', $context['run_id'] ?? '');
    }

    public function test_conversation_start_includes_metadata(): void
    {
        $context = [];

        HookRegistry::on('conversation.start', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = $this->makeAgent($this->makeSuccessProvider());
        $conv = $agent->conversation(metadata: ['user_id' => 42, 'channel' => 'web']);
        $agent->sendInConversation($conv, 'hi');

        $this->assertSame(['user_id' => 42, 'channel' => 'web'], $context['metadata'] ?? null);
    }

    public function test_conversation_start_fires_once_not_on_later_turns_or_reload(): void
    {
        $fireCount = 0;

        HookRegistry::on('conversation.start', function () use (&$fireCount): void {
            $fireCount++;
        });

        $agent = $this->makeAgent($this->makeSuccessProvider());
        $conv = $agent->conversation();

        $turn = $agent->sendInConversation($conv, 'first');
        $this->assertSame(1, $fireCount);

        $agent->sendInConversation($turn->conversation, 'second');
        $this->assertSame(1, $fireCount, 'conversation.start must fire only on the first turn');

        $agent->sendInConversation($agent->conversation($conv->id), 'third');
        $this->assertSame(1, $fireCount, 'conversation.start must NOT fire when continuing an existing conversation');
    }

    public function test_conversation_start_fires_on_first_send_without_memory_driver(): void
    {
        $fired = false;

        HookRegistry::on('conversation.start', function () use (&$fired): void {
            $fired = true;
        });

        $agent = Claw::builder()
            ->providerOverride($this->makeSuccessProvider())
            ->useDefaultGuards(false)
            ->build();

        $agent->sendInConversation($agent->conversation(), 'hi');

        $this->assertTrue($fired, 'conversation.start must fire on the first send even without a memory driver');
    }

    public function test_conversation_end_fires_after_send_in_conversation(): void
    {
        $context = [];

        HookRegistry::on('conversation.end', function (array $ctx) use (&$context): void {
            $context = $ctx;
        });

        $agent = $this->makeAgent($this->makeSuccessProvider('Good answer'));
        $conv = $agent->conversation();
        $agent->sendInConversation($conv, 'What is 2+2?');

        $this->assertSame($conv->id, $context['conversation_id'] ?? null);
        $this->assertSame('What is 2+2?', $context['message'] ?? null);
        $this->assertSame('Good answer', $context['response'] ?? null);
        $this->assertIsInt($context['duration_ms'] ?? null);
    }

    public function test_conversation_end_turn_count_increments_per_turn(): void
    {
        $turnCounts = [];

        HookRegistry::on('conversation.end', function (array $ctx) use (&$turnCounts): void {
            $turnCounts[] = $ctx['turn_count'];
        });

        $agent = $this->makeAgent($this->makeSuccessProvider());
        $conv = $agent->conversation();

        $turn1 = $agent->sendInConversation($conv, 'First message');
        $agent->sendInConversation($turn1->conversation, 'Second message');

        $this->assertSame([2, 4], $turnCounts);
    }

    public function test_conversation_end_does_not_fire_when_agent_throws(): void
    {
        $fired = false;

        HookRegistry::on('conversation.end', function () use (&$fired): void {
            $fired = true;
        });

        $agent = $this->makeAgent($this->makeFailingProvider());
        $conv = $agent->conversation();

        try {
            $agent->sendInConversation($conv, 'hello');
        } catch (ProviderException) {
        }

        $this->assertFalse($fired, 'conversation.end must NOT fire when the agent throws');
    }

    public function test_conversation_end_does_not_fire_for_single_turn_send(): void
    {
        $fired = false;

        HookRegistry::on('conversation.end', function () use (&$fired): void {
            $fired = true;
        });

        $agent = Claw::builder()
            ->providerOverride($this->makeSuccessProvider())
            ->useDefaultGuards(false)
            ->build();
        $agent->send('hello');

        $this->assertFalse($fired, 'conversation.end must NOT fire for single-turn Claw::send()');
    }
}
