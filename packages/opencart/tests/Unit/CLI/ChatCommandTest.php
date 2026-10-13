<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\CLI;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\OpenCart\CLI\ChatCommand;
use PhpClaw\OpenCart\Exceptions\ConversationAccessDeniedException;
use PhpClaw\OpenCart\Tests\Unit\CLI\Fixtures\ScriptedProvider;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\TestCase;

final class ChatCommandTest extends TestCase
{
    private ScriptedProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();
        $this->provider = new ScriptedProvider;
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        ProviderRegistry::reset();
        MemoryRegistry::reset();
        parent::tearDown();
    }

    private function makeEngine(?ScriptedProvider $provider = null): ClawInterface
    {
        $p = $provider ?? $this->provider;
        ProviderRegistry::register('scripted', static fn () => $p);
        MemoryRegistry::register('oc_router', static fn () => new ArrayMemory);

        return Claw::builder()
            ->providerOverride($p)
            ->apiKey('test-key')
            ->memory(new ArrayMemory)
            ->build();
    }

    private function chat(
        array $lines,
        string $convId = '',
        bool $continue = false,
        bool $isTerminal = true,
        ?ClawInterface $engineOverride = null,
        ?string $storageDir = null,
    ): array {
        $engine = $engineOverride ?? $this->makeEngine();
        $factory = static function (string $p, string $m) use ($engine): ClawInterface {
            return $engine;
        };
        $dir = $storageDir ?? sys_get_temp_dir().'/phpclaw-test-'.uniqid();

        $input = fopen('php://memory', 'r+');
        fwrite($input, implode(PHP_EOL, $lines).($lines === [] ? '' : PHP_EOL));
        rewind($input);
        $output = tmpfile();

        $exitCode = null;
        (new ChatCommand(
            $factory,
            $dir,
            'phpclaw.php',
            $input,
            $output,
            $isTerminal,
            false,
            function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
        ))->run(conversationId: $convId, continue: $continue);

        rewind($output);

        return ['out' => (string) stream_get_contents($output), 'exit' => $exitCode];
    }

    private function conversationId(string $out): string
    {
        preg_match('/Conversation: ([0-9A-Z]{26})/', $out, $m);

        return $m[1] ?? '';
    }

    public function test_chat_runs_two_turns_in_one_conversation_with_a_summary_line(): void
    {
        ['out' => $out] = $this->chat(['first question', 'second question', '/exit']);

        self::assertStringContainsString('reply to first question', $out);
        self::assertStringContainsString('reply to second question', $out);
        self::assertSame(['first question', 'second question'], $this->provider->received);
        self::assertSame(3, substr_count($out, 'Conversation: '.$this->conversationId($out)));
        self::assertStringContainsString('Provider: scripted | Model: scripted-model | Tokens: ', $out);
    }

    public function test_chat_shows_the_banner_without_a_version_and_the_resume_line(): void
    {
        ['out' => $out] = $this->chat(['/exit']);

        self::assertStringContainsString('AI agents for OpenCart', $out);
        self::assertDoesNotMatchRegularExpression('/v\d+\.\d+\.\d+/', $out);
        self::assertStringContainsString('Resume with: php phpclaw.php chat --conv-id='.$this->conversationId($out), $out);
    }

    public function test_chat_ends_at_end_of_input_like_ctrl_d(): void
    {
        ['out' => $out] = $this->chat(['only question']);

        self::assertSame(['only question'], $this->provider->received);
        self::assertStringContainsString('Resume with: php phpclaw.php chat --conv-id=', $out);
    }

    public function test_chat_refuses_to_start_without_a_terminal(): void
    {
        ['out' => $out, 'exit' => $exit] = $this->chat(['hi'], isTerminal: false);

        self::assertSame(1, $exit);
        self::assertSame('', $out);
        self::assertSame([], $this->provider->received);
    }

    public function test_chat_ignores_an_empty_line_and_never_sends_a_slash_command(): void
    {
        ['out' => $out] = $this->chat(['', '   ', '/deploy now', 'what does a/b mean?', '/exit']);

        self::assertStringContainsString('Unknown command /deploy. Type /help for the list.', $out);
        self::assertSame(['what does a/b mean?'], $this->provider->received);
    }

    public function test_slash_help_lists_every_command(): void
    {
        ['out' => $out] = $this->chat(['/help', '/exit']);

        foreach (['/help', '/tools', '/model <name>', '/provider <slug>', '/new', '/conv', '/quiet', '/clear', '/exit'] as $slash) {
            self::assertStringContainsString($slash, $out);
        }
    }

    public function test_slash_tools_lists_the_engine_tools(): void
    {
        ['out' => $out] = $this->chat(['/tools', '/exit']);

        self::assertMatchesRegularExpression('/\d+ tools:/', $out);
    }

    public function test_slash_new_starts_a_new_conversation_and_prints_the_id(): void
    {
        ['out' => $out] = $this->chat(['/new', '/conv', '/exit']);

        preg_match('/New conversation: ([0-9A-Z]{26})/', $out, $m);
        self::assertNotEmpty($m[1] ?? '');
        self::assertStringContainsString('Conversation: '.$m[1], $out);
    }

    public function test_slash_model_and_provider_switch_for_the_session_only(): void
    {
        $other = new ScriptedProvider;
        $engine = $this->makeEngine($other);
        $called = [];
        $factory = static function (string $p, string $m) use ($engine, &$called): ClawInterface {
            $called[] = [$p, $m];

            return $engine;
        };

        $input = fopen('php://memory', 'r+');
        fwrite($input, "/model bigger-model\n/provider other\nhello\n/exit\n");
        rewind($input);
        $output = tmpfile();

        (new ChatCommand($factory, '', 'phpclaw.php', $input, $output, true, false))->run();

        rewind($output);
        $out = (string) stream_get_contents($output);

        self::assertStringContainsString('Model for this session: bigger-model', $out);
        self::assertStringContainsString('Provider for this session: other', $out);
        self::assertSame(['', ''], $called[0]);
        self::assertSame(['', 'bigger-model'], $called[1]);
        self::assertSame(['other', 'bigger-model'], $called[2]);
    }

    public function test_slash_model_and_provider_without_a_value_print_the_usage(): void
    {
        ['out' => $out] = $this->chat(['/model', '/provider', '/exit']);

        self::assertStringContainsString('Usage: /model <name>', $out);
        self::assertStringContainsString('Usage: /provider <slug>', $out);
    }

    public function test_a_provider_that_cannot_be_built_names_the_cause_and_keeps_the_engine(): void
    {
        $engine = $this->makeEngine();
        $factory = function (string $p, string $m) use ($engine): ClawInterface {
            if ($p === 'nope') {
                throw new \RuntimeException('No API key in /var/secret');
            }

            return $engine;
        };

        $input = fopen('php://memory', 'r+');
        fwrite($input, "/provider nope\nstill here\n/exit\n");
        rewind($input);
        $output = tmpfile();

        (new ChatCommand($factory, '', 'phpclaw.php', $input, $output, true, false))->run();

        rewind($output);
        $out = (string) stream_get_contents($output);

        self::assertStringContainsString('Cannot switch to provider nope: the provider name is unknown or its API key is not saved in phpClaw settings.', $out);
        self::assertStringNotContainsString('/var/secret', $out);
        self::assertStringContainsString('reply to still here', $out);
    }

    public function test_a_blocked_message_returns_to_the_prompt(): void
    {
        $this->provider = new ScriptedProvider(static function (string $message): void {
            if ($message === 'bad') {
                throw new GuardException('Prompt injection detected in /var/www/secret.txt');
            }
        });

        ['out' => $out] = $this->chat(['bad', 'good', '/exit']);

        self::assertStringContainsString('Your message was blocked by a security guard. Rephrase your prompt and try again.', $out);
        self::assertStringNotContainsString('/var/www/secret.txt', $out);
        self::assertStringContainsString('reply to good', $out);
    }

    public function test_any_other_agent_failure_prints_one_line_and_the_session_goes_on(): void
    {
        $this->provider = new ScriptedProvider(static function (string $message): void {
            if ($message === 'fail') {
                throw new ProviderException('Internal server error at /var/secret', statusCode: 500);
            }
        });

        ['out' => $out] = $this->chat(['fail', 'again', '/exit']);

        self::assertStringContainsString('An error occurred. Check your API key and provider settings.', $out);
        self::assertStringNotContainsString('/var/secret', $out);
        self::assertStringContainsString('reply to again', $out);
    }

    public function test_quiet_toggles_tool_output_off_and_on(): void
    {
        $this->provider = new ScriptedProvider(static function (string $message): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls '.$message]]);
        });

        ['out' => $out] = $this->chat(['first', '/quiet', 'second', '/quiet', 'third', '/exit']);

        self::assertStringContainsString('> shell_exec: ls first', $out);
        self::assertStringContainsString('Tool trace off.', $out);
        self::assertStringNotContainsString('> shell_exec: ls second', $out);
        self::assertStringContainsString('Tool trace on.', $out);
        self::assertStringContainsString('> shell_exec: ls third', $out);
    }

    public function test_slash_clear_clears_the_screen(): void
    {
        ['out' => $out] = $this->chat(['/clear', '/exit']);

        self::assertStringContainsString("\033[2J\033[H", $out);
    }

    public function test_continue_passes_the_saved_id_to_the_engine(): void
    {
        $dir = sys_get_temp_dir().'/phpclaw-test-continue-'.uniqid();
        $captured = null;

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturnCallback(
            function (?string $id = null) use (&$captured): Conversation {
                $captured = $id;
                $convId = ($id !== null && $id !== '') ? $id : '01FRESHCONVID00GHJKMNPQRS0';

                return new Conversation(id: $convId, history: [], createdAt: new \DateTimeImmutable);
            },
        );
        $engine->method('streamInConversation')->willReturnCallback(
            fn (Conversation $c): ConversationTurn => new ConversationTurn(
                new AgentResponse(text: 'ok', provider: 'scripted', model: 'm', iterations: 1),
                $c,
            ),
        );
        $factory = static function (string $p, string $m) use ($engine): ClawInterface {
            return $engine;
        };

        $input = fopen('php://memory', 'r+');
        fwrite($input, "hello\n/exit\n");
        rewind($input);
        $output = tmpfile();
        (new ChatCommand($factory, $dir, 'phpclaw.php', $input, $output, true, false))->run();
        rewind($output);
        preg_match('/Conversation: ([0-9A-Z]{26})/', (string) stream_get_contents($output), $m);
        $savedId = $m[1] ?? '';
        self::assertNotEmpty($savedId);

        $captured = null;
        $input2 = fopen('php://memory', 'r+');
        fwrite($input2, "/exit\n");
        rewind($input2);
        $output2 = tmpfile();
        (new ChatCommand($factory, $dir, 'phpclaw.php', $input2, $output2, true, false))->run(continue: true);

        self::assertSame($savedId, $captured);
    }

    public function test_continue_without_a_saved_conversation_starts_a_new_one(): void
    {
        ['out' => $out] = $this->chat(['/exit'], continue: true, storageDir: sys_get_temp_dir().'/phpclaw-test-empty-'.uniqid());

        self::assertStringContainsString('No previous conversation to continue; starting a new one.', $out);
    }

    public function test_an_unknown_conv_id_says_a_new_conversation_was_started(): void
    {
        ['out' => $out] = $this->chat(['/exit'], convId: '01OCNOSUCHCONVERSATION00AA');

        self::assertStringContainsString('No stored conversation 01OCNOSUCHCONVERSATION00AA; started '.$this->conversationId($out).'.', $out);
    }

    public function test_another_users_conv_id_prints_the_clean_line(): void
    {
        $accessDenied = false;
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturnCallback(
            function (?string $id = null) use (&$accessDenied): Conversation {
                if (! $accessDenied && $id !== null && $id !== '') {
                    $accessDenied = true;
                    throw new ConversationAccessDeniedException('denied');
                }

                return Conversation::start();
            },
        );
        $engine->method('streamInConversation')->willReturnCallback(
            fn (Conversation $c) => new ConversationTurn(
                new AgentResponse(text: 'ok', provider: 'scripted', model: 'm', iterations: 1),
                $c,
            ),
        );
        $factory = static function (string $p, string $m) use ($engine): ClawInterface {
            return $engine;
        };

        $input = fopen('php://memory', 'r+');
        fwrite($input, "/exit\n");
        rewind($input);
        $output = tmpfile();

        (new ChatCommand($factory, '', 'phpclaw.php', $input, $output, true, false))->run(conversationId: '01OTHERUSER0CONVERSATION0');

        rewind($output);
        $out = (string) stream_get_contents($output);

        self::assertStringContainsString('is not available to the console; started', $out);
        self::assertStringNotContainsString('denied', $out);
    }

    public function test_tool_output_listeners_are_cleaned_up_when_the_session_ends(): void
    {
        $before = HookRegistry::count();

        $factory = fn (string $p, string $m): ClawInterface => $this->makeEngine();
        $input = fopen('php://memory', 'r+');
        fwrite($input, "/exit\n");
        rewind($input);
        $output = tmpfile();

        (new ChatCommand($factory, '', 'phpclaw.php', $input, $output, true, false))->run();

        self::assertSame($before, HookRegistry::count());
    }

    public function test_engine_build_failure_exits_cleanly_without_exception_details(): void
    {
        $factory = static function (string $p, string $m): ClawInterface {
            throw new \RuntimeException('API key missing from /etc/secret/phpclaw.key');
        };

        $input = fopen('php://memory', 'r+');
        fwrite($input, "hello\n");
        rewind($input);
        $output = tmpfile();

        $exitCode = null;
        (new ChatCommand(
            $factory,
            '',
            'phpclaw.php',
            $input,
            $output,
            true,
            false,
            function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
        ))->run();

        rewind($output);
        $out = (string) stream_get_contents($output);

        self::assertSame(1, $exitCode);
        self::assertStringNotContainsString('/etc/secret', $out);
    }

    public function test_the_summary_line_lists_each_tool_the_turn_used_once(): void
    {
        $command = new ChatCommand(
            fn (string $p, string $m): ClawInterface => $this->makeEngine(),
            '',
            'phpclaw.php',
            fopen('php://memory', 'r'),
            tmpfile(),
            isTerminal: true,
            decorated: false,
        );
        $turn = new ConversationTurn(
            new AgentResponse(text: 'ok', provider: 'scripted', model: 'scripted-model', iterations: 2, toolsCalled: ['oc_product', 'oc_product', 'oc_order']),
            Conversation::start(),
        );

        $summary = (new \ReflectionMethod($command, 'turnSummary'))->invoke($command, $turn);

        self::assertStringContainsString('| Tools: oc_product, oc_order | Conversation: '.$turn->conversation->id, $summary);
    }
}
