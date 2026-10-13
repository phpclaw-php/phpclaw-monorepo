<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\CLI;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Claw;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\PrestaShop\CLI\ChatCommand;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Exceptions\ConversationAccessDeniedException;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatCommand::class)]
final class ChatCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private int $lastExitCode = -1;

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();
        MemoryRegistry::reset();
        ProviderRegistry::reset();
        \Configuration::reset();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        MemoryRegistry::reset();
        ProviderRegistry::reset();
        \Configuration::reset();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        parent::tearDown();
    }

    private function makeConversation(string $id = 'chat-conv-01'): Conversation
    {
        return new Conversation(id: $id, history: [], createdAt: new \DateTimeImmutable);
    }

    private function makeResponse(string $text = 'agent reply', string $provider = 'anthropic', string $model = 'claude'): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: $provider,
            model: $model,
            iterations: 1,
            inputTokens: 11,
            outputTokens: 3,
        );
    }

    private function makePlugin(ClawInterface $engine): Plugin
    {
        $plugin = Plugin::getInstance($this->createMock(PsDbInterface::class), 'ps_');

        $ref = new \ReflectionProperty(Plugin::class, 'engine');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $engine);

        return $plugin;
    }

    private function makeEngine(string $replyText = 'agent reply', ?\Closure $sideEffect = null): ClawInterface
    {
        $conv = $this->makeConversation();
        $engine = \Mockery::mock(ClawInterface::class);

        $engine->allows('conversation')->andReturnUsing(static fn (string $id = '') => new Conversation(
            id: $id !== '' ? $id : 'chat-conv-01',
            history: [],
            createdAt: new \DateTimeImmutable,
        ));

        $engine->allows('streamInConversation')->andReturnUsing(
            function (Conversation $conversation, string $message, callable $cb) use ($replyText, $sideEffect): ConversationTurn {
                if ($sideEffect !== null) {
                    ($sideEffect)($message);
                }

                $cb($replyText);

                return new ConversationTurn(
                    response: $this->makeResponse($replyText),
                    conversation: $conversation,
                );
            },
        );

        return $engine;
    }

    private function chat(Plugin $plugin, array $lines, array $flags = [], bool $isTerminal = true): string
    {
        $input = fopen('php://memory', 'r+');
        fwrite($input, implode(PHP_EOL, $lines).($lines === [] ? '' : PHP_EOL));
        rewind($input);
        $output = tmpfile();

        (new ChatCommand(
            $plugin,
            $input,
            $output,
            isTerminal: $isTerminal,
            decorated: false,
            exitFn: function (int $code): void {
                $this->lastExitCode = $code;
            },
        ))->run($flags);

        rewind($output);

        return (string) stream_get_contents($output);
    }

    private function convId(string $out): string
    {
        preg_match('/Conversation: ([0-9A-Za-z_-]{1,64})/', $out, $m);

        return $m[1] ?? '';
    }

    public function test_chat_shows_banner_tagline_and_no_version(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/exit']);

        self::assertStringContainsString('AI agents for PrestaShop', $out);
        self::assertDoesNotMatchRegularExpression('/v\d+\.\d+\.\d+/', $out);
    }

    public function test_chat_shows_resume_line_on_exit(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/exit']);

        self::assertStringContainsString('Resume with: php modules/phpclaw/cli/phpclaw.php chat --conv-id=', $out);
    }

    public function test_chat_refuses_without_terminal_and_exits_one(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, [], isTerminal: false);

        self::assertSame('', $out);
        self::assertSame(1, $this->lastExitCode);
    }

    public function test_chat_sends_message_and_prints_reply(): void
    {
        $engine = $this->makeEngine('hello world');
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['what can you do?', '/exit']);

        self::assertStringContainsString('hello world', $out);
    }

    public function test_chat_prints_summary_after_each_turn(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['question one', '/exit']);

        self::assertStringContainsString('Provider: anthropic | Model: claude | Tokens: 11', $out);
        self::assertStringContainsString('Conversation: ', $out);
    }

    public function test_chat_empty_line_does_not_send_to_agent(): void
    {
        $received = [];
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andReturnUsing(
            function (Conversation $c, string $msg, callable $cb) use (&$received): ConversationTurn {
                $received[] = $msg;
                $cb('ok');

                return new ConversationTurn(response: $this->makeResponse(), conversation: $c);
            },
        );

        $plugin = $this->makePlugin($engine);
        $this->chat($plugin, ['', '   ', 'real message', '/exit']);

        self::assertSame(['real message'], $received);
    }

    public function test_slash_command_is_not_sent_to_agent(): void
    {
        $received = [];
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andReturnUsing(
            function (Conversation $c, string $msg, callable $cb) use (&$received): ConversationTurn {
                $received[] = $msg;
                $cb('ok');

                return new ConversationTurn(response: $this->makeResponse(), conversation: $c);
            },
        );

        $plugin = $this->makePlugin($engine);
        $this->chat($plugin, ['/help', 'real question', '/exit']);

        self::assertSame(['real question'], $received);
    }

    public function test_slash_help_lists_all_commands(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/help', '/exit']);

        foreach (['/help', '/tools', '/model <name>', '/provider <slug>', '/new', '/conv', '/quiet', '/clear', '/exit'] as $cmd) {
            self::assertStringContainsString($cmd, $out);
        }
    }

    public function test_slash_tools_lists_active_tools(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/tools', '/exit']);

        self::assertMatchesRegularExpression('/\d+ tools:/', $out);
    }

    public function test_slash_new_starts_fresh_conversation(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/new', '/conv', '/exit']);

        preg_match('/New conversation: ([0-9A-Za-z_-]{1,64})/', $out, $m);
        self::assertNotEmpty($m[1] ?? '');
        self::assertStringContainsString('Conversation: '.$m[1], $out);
    }

    public function test_slash_conv_prints_current_conversation_id(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/conv', '/exit']);

        self::assertStringContainsString('Conversation: chat-conv-01', $out);
    }

    public function test_slash_quiet_turns_tool_output_off_and_on(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andReturnUsing(
            function (Conversation $c, string $msg, callable $cb): ConversationTurn {
                HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls '.$msg]]);
                $cb('ok');

                return new ConversationTurn(response: $this->makeResponse(), conversation: $c);
            },
        );

        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['first', '/quiet', 'second', '/quiet', 'third', '/exit']);

        self::assertStringContainsString('> shell_exec: ls first', $out);
        self::assertStringContainsString('Tool trace off.', $out);
        self::assertStringNotContainsString('> shell_exec: ls second', $out);
        self::assertStringContainsString('Tool trace on.', $out);
        self::assertStringContainsString('> shell_exec: ls third', $out);
    }

    public function test_slash_clear_writes_escape_sequence(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/clear', '/exit']);

        self::assertStringContainsString("\033[2J\033[H", $out);
    }

    public function test_slash_model_without_arg_prints_usage(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/model', '/exit']);

        self::assertStringContainsString('Usage: /model <name>', $out);
    }

    public function test_slash_model_changes_engine_for_session_only(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $plugin->saveSettings(['provider' => 'ollama', 'model' => 'qwen2.5:7b', 'api_key' => '']);

        $engineRef = new \ReflectionProperty(Plugin::class, 'engine');
        $engineRef->setAccessible(true);
        $engineRef->setValue($plugin, $engine);

        $savedBefore = \Configuration::get('PHPCLAW_MODEL');

        $out = $this->chat($plugin, ['/model llama3.1:8b', '/exit']);

        self::assertStringContainsString('Model for this session: llama3.1:8b', $out);
        self::assertSame($savedBefore, \Configuration::get('PHPCLAW_MODEL'));
    }

    public function test_slash_provider_without_arg_prints_usage(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/provider', '/exit']);

        self::assertStringContainsString('Usage: /provider <slug>', $out);
    }

    public function test_unknown_provider_prints_cannot_switch_and_keeps_engine(): void
    {
        $received = [];
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andReturnUsing(
            function (Conversation $c, string $msg, callable $cb) use (&$received): ConversationTurn {
                $received[] = $msg;
                $cb('ok');

                return new ConversationTurn(response: $this->makeResponse(), conversation: $c);
            },
        );

        $plugin = $this->makePlugin($engine);
        $plugin->saveSettings(['provider' => 'anthropic', 'model' => 'claude', 'api_key' => 'sk-test']);

        $engineRef = new \ReflectionProperty(Plugin::class, 'engine');
        $engineRef->setAccessible(true);
        $engineRef->setValue($plugin, $engine);

        $out = $this->chat($plugin, ['/provider nope', 'still works', '/exit']);

        self::assertStringContainsString('Cannot switch to provider nope', $out);
        self::assertSame(['still works'], $received);
    }

    public function test_unknown_slash_command_prints_message(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/deploy now', '/exit']);

        self::assertStringContainsString('Unknown command /deploy', $out);
    }

    public function test_guard_failure_prints_clean_message_and_session_continues(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andReturnUsing(
            function (Conversation $c, string $msg, callable $cb): ConversationTurn {
                if ($msg === 'bad') {
                    throw new GuardException('Leaked path /var/secret.txt');
                }

                $cb('safe reply');

                return new ConversationTurn(response: $this->makeResponse('safe reply'), conversation: $c);
            },
        );

        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['bad', 'good', '/exit']);

        self::assertStringNotContainsString('/var/secret.txt', $out);
        self::assertStringContainsString('Your message was blocked', $out);
        self::assertStringContainsString('safe reply', $out);
    }

    public function test_any_failure_prints_one_line_and_session_continues(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andReturnUsing(
            function (Conversation $c, string $msg, callable $cb): ConversationTurn {
                if ($msg === 'break') {
                    throw new ToolException('disk full /var/data');
                }

                $cb('recovered');

                return new ConversationTurn(response: $this->makeResponse('recovered'), conversation: $c);
            },
        );

        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['break', 'ok', '/exit']);

        self::assertStringNotContainsString('/var/data', $out);
        self::assertStringContainsString('An error occurred', $out);
        self::assertStringContainsString('recovered', $out);
    }

    public function test_continue_opens_last_conversation(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);

        $first = $this->chat($plugin, ['hello', '/exit']);
        $firstId = $this->convId($first);
        self::assertNotEmpty($firstId);

        $second = $this->chat($plugin, ['/exit'], ['continue' => 'true']);
        $secondId = $this->convId($second);

        self::assertSame($firstId, $secondId);
    }

    public function test_continue_without_saved_conversation_starts_new(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/exit'], ['continue' => 'true']);

        self::assertStringContainsString('No previous conversation to continue; starting a new one.', $out);
    }

    public function test_unknown_conv_id_says_new_conversation_was_started(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturnUsing(static fn (string $id = '') => new Conversation(
            id: 'new-fresh-conv-generated',
            history: [],
            createdAt: new \DateTimeImmutable,
        ));
        $engine->allows('streamInConversation')->andReturnUsing(
            static fn (Conversation $c, string $m, callable $cb): ConversationTurn => new ConversationTurn(
                response: new AgentResponse(text: 'ok', provider: 'p', model: 'm', iterations: 1),
                conversation: $c,
            ),
        );

        $plugin = $this->makePlugin($engine);
        $out = $this->chat($plugin, ['/exit'], ['conv-id' => '01PSNOSUCHCONV00000000001']);

        self::assertStringContainsString('No stored conversation 01PSNOSUCHCONV00000000001; started ', $out);
    }

    public function test_listeners_are_removed_after_session_ends(): void
    {
        $engine = $this->makeEngine();
        $plugin = $this->makePlugin($engine);
        $before = HookRegistry::count();

        $this->chat($plugin, ['/exit']);

        self::assertSame($before, HookRegistry::count());
    }

    public function test_engine_build_failure_prints_clean_line(): void
    {
        $plugin = Plugin::getInstance($this->createMock(PsDbInterface::class), 'ps_');

        $errorRef = new \ReflectionProperty(Plugin::class, 'engineError');
        $errorRef->setAccessible(true);
        $errorRef->setValue($plugin, new \RuntimeException('key leaked at /var/secret'));

        $input = fopen('php://memory', 'r+');
        $output = tmpfile();

        (new ChatCommand(
            $plugin,
            $input,
            $output,
            isTerminal: true,
            decorated: false,
            exitFn: function (int $code): void {
                $this->lastExitCode = $code;
            },
        ))->run([]);

        rewind($output);
        $out = (string) stream_get_contents($output);

        self::assertStringNotContainsString('/var/secret', $out);
        self::assertSame(1, $this->lastExitCode);
    }

    public function test_chat_ends_at_the_end_of_input_like_ctrl_d(): void
    {
        $received = [];
        $plugin = $this->makePlugin($this->makeEngine(sideEffect: static function (string $message) use (&$received): void {
            $received[] = $message;
        }));

        $out = $this->chat($plugin, ['only question']);

        self::assertSame(['only question'], $received);
        self::assertStringContainsString('Resume with: php modules/phpclaw/cli/phpclaw.php chat --conv-id=', $out);
    }

    public function test_a_conversation_the_console_may_not_read_prints_one_line_and_starts_a_new_one(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturnUsing(function (string $id = ''): Conversation {
            if ($id === '01PSSOMEONEELSE0000000000A') {
                throw new ConversationAccessDeniedException;
            }

            return $this->makeConversation('01PSNEWCONVERSATION0000000');
        });

        $out = $this->chat($this->makePlugin($engine), ['/exit'], ['conv-id' => '01PSSOMEONEELSE0000000000A']);

        self::assertStringContainsString('Conversation 01PSSOMEONEELSE0000000000A is not available to the console; started 01PSNEWCONVERSATION0000000.', $out);
        self::assertStringContainsString('Conversation: 01PSNEWCONVERSATION0000000', $out);
    }

    public function test_a_saved_id_that_is_not_a_conversation_id_is_ignored(): void
    {
        \Configuration::updateValue('PHPCLAW_CLI_LAST_CONV', '../../etc/passwd');
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->shouldReceive('conversation')->once()->with('')->andReturn($this->makeConversation());

        $out = $this->chat($this->makePlugin($engine), ['/exit'], ['continue' => 'true']);

        self::assertStringContainsString('No previous conversation to continue; starting a new one.', $out);
    }

    public function test_slash_tools_lists_the_tools_of_a_phpclaw_engine(): void
    {
        $tool = \Mockery::mock(ToolInterface::class);
        $tool->allows('name')->andReturn('ps_order');
        $tool->allows('description')->andReturn('orders');
        $tool->allows('parameters')->andReturn([]);
        $engine = Claw::builder()->provider('ollama')->model('qwen2.5:7b')->tools([$tool])->build();

        $out = $this->chat($this->makePlugin($engine), ['/tools', '/exit']);

        self::assertStringContainsString('1 tools: ps_order', $out);
        self::assertStringContainsString('Provider: ollama | Model: qwen2.5:7b | Tools: 1', $out);
    }

    public function test_the_summary_line_lists_each_tool_the_turn_used_once(): void
    {
        $command = new ChatCommand($this->makePlugin($this->makeEngine()), fopen('php://memory', 'r'), tmpfile(), isTerminal: true, decorated: false);
        $turn = new ConversationTurn(
            new AgentResponse(text: 'ok', provider: 'ollama', model: 'qwen2.5:7b', iterations: 2, inputTokens: 5, outputTokens: 2, toolsCalled: ['ps_order', 'ps_order', 'ps_stock']),
            $this->makeConversation('01PSSUMMARY000000000000000'),
        );

        $summary = (new \ReflectionMethod($command, 'turnSummary'))->invoke($command, $turn);

        self::assertSame('Provider: ollama | Model: qwen2.5:7b | Tokens: 5→2 | Tools: ps_order, ps_stock | Conversation: 01PSSUMMARY000000000000000', $summary);
    }
}
