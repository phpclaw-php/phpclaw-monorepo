<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Claw;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class MemoryInjectionTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    private function captureProvider(string &$captured, string $reply = 'OK'): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturnCallback(
            function (array $messages) use (&$captured, $reply): array {
                foreach (array_reverse($messages) as $msg) {
                    if ($msg->role === 'user') {
                        $captured = $msg->content;
                        break;
                    }
                }

                return ['type' => 'text', 'text' => $reply];
            }
        );

        return $mock;
    }

    public function test_message_unchanged_when_no_memory_set(): void
    {
        $captured = '';
        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->useDefaultGuards(false)
            ->build();

        $agent->send('hello world');

        $this->assertSame('hello world', $captured);
    }

    public function test_message_unchanged_when_memory_is_empty(): void
    {
        $captured = '';
        $memory = new ArrayMemory;

        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->memory($memory)
            ->useDefaultGuards(false)
            ->build();

        $agent->send('hello world');

        $this->assertSame('hello world', $captured);
    }

    public function test_message_unchanged_when_no_word_overlap_with_memory(): void
    {
        $captured = '';
        $memory = new ArrayMemory;
        $memory->set('key1', 'zzz qqq xxx');

        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->memory($memory)
            ->useDefaultGuards(false)
            ->build();

        $agent->send('hello world');

        $this->assertSame('hello world', $captured);
    }

    public function test_matching_memory_entry_is_prepended_to_message(): void
    {
        $captured = '';
        $memory = new ArrayMemory;
        $memory->set('user_pref', 'user prefers dark mode');

        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->memory($memory)
            ->useDefaultGuards(false)
            ->build();

        $agent->send('what does the user prefer');

        $this->assertStringContainsString('[Context from memory]', $captured);
        $this->assertStringContainsString('user_pref', $captured);
        $this->assertStringContainsString('[User message]', $captured);
        $this->assertStringContainsString('what does the user prefer', $captured);
    }

    public function test_injected_memory_content_is_scanned_by_guards(): void
    {
        GuardRegistry::register(new class implements GuardInterface
        {
            public function scan(string $message): void
            {
                if (str_contains($message, 'INJECTED_ATTACK')) {
                    throw new GuardException('blocked injected content');
                }
            }
        });

        $memory = new ArrayMemory;
        $memory->set('notes', 'the user notes contain INJECTED_ATTACK payload');

        $captured = '';
        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->memory($memory)
            ->useDefaultGuards(false)
            ->build();

        $this->expectException(GuardException::class);
        $agent->send('what are the user notes');
    }

    public function test_at_most_three_entries_injected_when_more_match(): void
    {
        $captured = '';
        $memory = new ArrayMemory;

        for ($i = 1; $i <= 5; $i++) {
            $memory->set("key{$i}", "user preference entry number {$i}");
        }

        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->memory($memory)
            ->useDefaultGuards(false)
            ->build();

        $agent->send('tell me about user preferences');

        $this->assertLessThanOrEqual(3, substr_count($captured, '- key'));
    }

    public function test_original_message_preserved_verbatim_after_prefix(): void
    {
        $captured = '';
        $memory = new ArrayMemory;
        $memory->set('note', 'remember the database user');

        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->memory($memory)
            ->useDefaultGuards(false)
            ->build();

        $original = 'check the database connection';
        $agent->send($original);

        $this->assertStringEndsWith($original, $captured);
    }

    public function test_non_string_memory_value_is_json_encoded_in_prefix(): void
    {
        $captured = '';
        $memory = new ArrayMemory;
        $memory->set('config', ['theme' => 'dark', 'lang' => 'en']);

        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->memory($memory)
            ->useDefaultGuards(false)
            ->build();

        $agent->send('what is the config theme');

        $this->assertStringContainsString('[Context from memory]', $captured);
        $this->assertStringContainsString('config', $captured);
    }

    public function test_memory_injected_in_send_in_conversation(): void
    {
        $captured = '';
        $memory = new ArrayMemory;
        $memory->set('info', 'user likes dark theme');

        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->memory($memory)
            ->useDefaultGuards(false)
            ->build();

        $conv = $agent->conversation();
        $agent->sendInConversation($conv, 'what does the user like');

        $this->assertStringContainsString('[Context from memory]', $captured);
    }

    public function test_injected_memory_is_scanned_in_conversation_path(): void
    {
        GuardRegistry::register(new class implements GuardInterface
        {
            public function scan(string $message): void
            {
                if (str_contains($message, 'INJECTED_ATTACK')) {
                    throw new GuardException('blocked injected content');
                }
            }
        });

        $memory = new ArrayMemory;
        $memory->set('notes', 'the user notes contain INJECTED_ATTACK payload');

        $captured = '';
        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->memory($memory)
            ->useDefaultGuards(false)
            ->build();

        $conv = $agent->conversation();

        $this->expectException(GuardException::class);
        $agent->sendInConversation($conv, 'what are the user notes');
    }

    public function test_guard_blocked_reports_raw_message_not_augmented(): void
    {
        GuardRegistry::register(new class implements GuardInterface
        {
            public function scan(string $message): void
            {
                if (str_contains($message, 'INJECTED_ATTACK')) {
                    throw new GuardException('blocked injected content');
                }
            }
        });

        $reported = null;
        HookRegistry::on('guard.blocked', function (array $ctx) use (&$reported): void {
            $reported = $ctx['message'] ?? null;
        });

        $memory = new ArrayMemory;
        $memory->set('notes', 'the user notes contain INJECTED_ATTACK payload');

        $captured = '';
        $agent = Claw::builder()
            ->providerOverride($this->captureProvider($captured))
            ->memory($memory)
            ->useDefaultGuards(false)
            ->build();

        try {
            $agent->send('what are the user notes');
        } catch (GuardException) {
        }

        $this->assertSame('what are the user notes', $reported);
        $this->assertStringNotContainsString('INJECTED_ATTACK', (string) $reported);
    }
}
