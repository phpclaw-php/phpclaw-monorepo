<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\CLI;

require_once __DIR__.'/Fixtures/wp-cli-stub.php';

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\WordPress\CLI\ChatCommand;
use PhpClaw\WordPress\Plugin;
use PhpClaw\WordPress\Tests\Unit\CLI\Fixtures\BuildsCliEngine;
use PhpClaw\WordPress\Tests\Unit\CLI\Fixtures\ScriptedProvider;
use PHPUnit\Framework\TestCase;

final class ChatCommandTest extends TestCase
{
    use BuildsCliEngine;
    use MockeryPHPUnitIntegration;

    private ScriptedProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        HookRegistry::reset();
        \WP_CLI::$log = [];
        \WP_CLI::$error = null;
        \WP_CLI::$success = null;
        $this->provider = new ScriptedProvider;
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        ProviderRegistry::reset();
        MemoryRegistry::reset();
        (new \ReflectionClass(Plugin::class))->getProperty('instance')->setValue(null, null);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function chat(array $lines, array $assocArgs = [], bool $isTerminal = true, bool $buildEngine = true): string
    {
        if ($buildEngine) {
            $this->useEngine($this->provider);
        }

        $input = fopen('php://memory', 'r+');
        fwrite($input, implode(PHP_EOL, $lines).($lines === [] ? '' : PHP_EOL));
        rewind($input);
        $output = tmpfile();

        (new ChatCommand($input, $output, isTerminal: $isTerminal, decorated: false))([], $assocArgs);

        rewind($output);

        return (string) stream_get_contents($output);
    }

    private function conversationId(string $out): string
    {
        preg_match('/Conversation: ([0-9A-Z]{26})/', $out, $m);

        return $m[1] ?? '';
    }

    public function test_chat_runs_two_turns_in_one_conversation_with_a_summary_line(): void
    {
        $out = $this->chat(['first question', 'second question', '/exit']);

        self::assertStringContainsString('reply to first question', $out);
        self::assertStringContainsString('reply to second question', $out);
        self::assertSame(['first question', 'second question'], $this->provider->received);
        self::assertSame(3, substr_count($out, 'Conversation: '.$this->conversationId($out)));
        self::assertStringContainsString('Provider: scripted | Model: scripted-model | Tokens: 11→3', $out);
    }

    public function test_chat_shows_the_banner_without_a_version_and_the_resume_line(): void
    {
        $out = $this->chat(['/exit']);

        self::assertStringContainsString('AI agents for WordPress', $out);
        self::assertDoesNotMatchRegularExpression('/v\d+\.\d+\.\d+/', $out);
        self::assertStringContainsString('Resume with: wp phpclaw chat --conv-id='.$this->conversationId($out), $out);
    }

    public function test_chat_ends_at_the_end_of_input_like_ctrl_d(): void
    {
        $out = $this->chat(['only question']);

        self::assertSame(['only question'], $this->provider->received);
        self::assertStringContainsString('Resume with: wp phpclaw chat --conv-id=', $out);
    }

    public function test_chat_refuses_to_start_without_a_terminal(): void
    {
        $out = $this->chat(['hi'], isTerminal: false);

        self::assertSame('wp phpclaw chat needs an interactive terminal. For one message use: wp phpclaw send "<message>"', \WP_CLI::$error);
        self::assertSame('', $out);
        self::assertSame([], $this->provider->received);
    }

    public function test_chat_ignores_an_empty_line_and_never_sends_a_slash_command(): void
    {
        $out = $this->chat(['', '   ', '/deploy now', 'what does a/b mean?', '/exit']);

        self::assertStringContainsString('Unknown command /deploy. Type /help for the list.', $out);
        self::assertSame(['what does a/b mean?'], $this->provider->received);
    }

    public function test_slash_help_lists_every_command(): void
    {
        $out = $this->chat(['/help', '/exit']);

        foreach (['/help', '/tools', '/model <name>', '/provider <slug>', '/new', '/conv', '/quiet', '/clear', '/exit'] as $slash) {
            self::assertStringContainsString($slash, $out);
        }
    }

    public function test_slash_tools_lists_the_engine_tools(): void
    {
        $out = $this->chat(['/tools', '/exit']);

        self::assertMatchesRegularExpression('/\d+ tools: \S+/', $out);
    }

    public function test_slash_new_and_conv_switch_to_a_new_conversation_and_save_it(): void
    {
        $out = $this->chat(['/new', '/conv', '/exit']);

        preg_match('/New conversation: ([0-9A-Z]{26})/', $out, $m);
        self::assertNotEmpty($m[1] ?? '');
        self::assertStringContainsString('Conversation: '.$m[1], $out);
        self::assertSame($m[1], $this->options['phpclaw_cli_last_conversation']);
    }

    public function test_slash_model_and_provider_switch_for_the_session_only(): void
    {
        ProviderRegistry::register('other', fn () => $this->provider);
        $this->useEngine($this->provider);
        ProviderRegistry::register('other', fn () => $this->provider);

        $out = $this->chat(['/model bigger-model', '/provider other', 'hello', '/exit'], buildEngine: false);

        self::assertStringContainsString('Model for this session: bigger-model', $out);
        self::assertStringContainsString('Provider: scripted | Model: bigger-model', $out);
        self::assertStringContainsString('Provider for this session: other', $out);
        self::assertStringContainsString('Provider: other | Model: bigger-model', $out);
        self::assertSame(['hello'], $this->provider->received);
        self::assertSame('scripted', $this->options['phpclaw_settings']['provider']);
        self::assertSame('scripted-model', $this->options['phpclaw_settings']['model']);
    }

    public function test_slash_model_and_provider_without_a_value_print_the_usage(): void
    {
        $out = $this->chat(['/model', '/provider', '/exit']);

        self::assertStringContainsString('Usage: /model <name>', $out);
        self::assertStringContainsString('Usage: /provider <slug>', $out);
    }

    public function test_a_provider_that_cannot_be_built_names_the_cause_and_keeps_the_engine(): void
    {
        $out = $this->chat(['/provider nope', 'still here', '/exit']);

        self::assertStringContainsString('Cannot switch to provider nope: the provider name is unknown or its API key is not saved in phpClaw settings.', $out);
        self::assertStringNotContainsString('Provider for this session: nope', $out);
        self::assertSame(['still here'], $this->provider->received);
    }

    public function test_a_blocked_message_returns_to_the_prompt(): void
    {
        $this->provider = new ScriptedProvider(static function (string $message): void {
            if ($message === 'bad') {
                throw new GuardException('Prompt injection detected in /var/www/secret.txt');
            }
        });

        $out = $this->chat(['bad', 'good', '/exit']);

        self::assertStringContainsString('Your message was blocked by a security guard. Rephrase your prompt and try again.', $out);
        self::assertStringNotContainsString('/var/www/secret.txt', $out);
        self::assertStringContainsString('reply to good', $out);
    }

    public function test_any_other_agent_failure_prints_one_line_and_the_session_goes_on(): void
    {
        $this->provider = new ScriptedProvider(static function (string $message): void {
            if ($message === 'break') {
                throw new ToolException('disk full at /var/secret');
            }
        });

        $out = $this->chat(['break', 'again', '/exit']);

        self::assertStringContainsString('An error occurred. Check your API key and provider settings.', $out);
        self::assertStringNotContainsString('/var/secret', $out);
        self::assertStringContainsString('reply to again', $out);
    }

    public function test_the_tool_trace_is_on_by_default_and_quiet_turns_it_off_and_on(): void
    {
        $this->provider = new ScriptedProvider(static function (string $message): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls '.$message]]);
        });

        $out = $this->chat(['first', '/quiet', 'second', '/quiet', 'third', '/exit']);

        self::assertStringContainsString('> shell_exec: ls first', $out);
        self::assertStringContainsString('Tool trace off.', $out);
        self::assertStringNotContainsString('> shell_exec: ls second', $out);
        self::assertStringContainsString('Tool trace on.', $out);
        self::assertStringContainsString('> shell_exec: ls third', $out);
    }

    public function test_slash_clear_clears_the_screen(): void
    {
        self::assertStringContainsString("\033[2J\033[H", $this->chat(['/clear', '/exit']));
    }

    public function test_continue_opens_the_last_conversation(): void
    {
        $first = $this->chat(['hello', '/exit']);
        $second = $this->chat(['/exit'], ['continue' => true], buildEngine: false);

        self::assertSame($this->conversationId($first), $this->conversationId($second));
    }

    public function test_continue_without_a_saved_conversation_starts_a_new_one(): void
    {
        $out = $this->chat(['/exit'], ['continue' => true]);

        self::assertStringContainsString('No previous conversation to continue; starting a new one.', $out);
    }

    public function test_an_unknown_conv_id_says_a_new_conversation_was_started(): void
    {
        $out = $this->chat(['/exit'], ['conv-id' => '01WPNOSUCHCONVERSATION00AA']);

        self::assertStringContainsString('No stored conversation 01WPNOSUCHCONVERSATION00AA; started '.$this->conversationId($out).'.', $out);
    }

    public function test_the_trace_and_spinner_listeners_are_removed_when_the_session_ends(): void
    {
        $this->useEngine($this->provider);
        $before = HookRegistry::count();

        $this->chat(['/exit'], buildEngine: false);

        self::assertSame($before, HookRegistry::count());
    }

    public function test_chat_refuses_when_the_engine_cannot_be_built(): void
    {
        $plugin = \Mockery::mock(Plugin::class);
        $plugin->allows('engine')->andThrow(new \RuntimeException('No API key in /var/secret'));
        (new \ReflectionClass(Plugin::class))->getProperty('instance')->setValue(null, $plugin);
        \Brain\Monkey\Functions\when('get_option')->justReturn([]);

        $out = $this->chat(['hi'], buildEngine: false);

        self::assertSame('An error occurred. Check your API key and provider settings.', \WP_CLI::$error);
        self::assertSame('', $out);
    }

    public function test_an_engine_that_is_not_a_phpclaw_engine_shows_no_tools(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn(Conversation::start());
        $plugin = \Mockery::mock(Plugin::class);
        $plugin->allows('engine')->andReturn($engine);
        (new \ReflectionClass(Plugin::class))->getProperty('instance')->setValue(null, $plugin);
        \Brain\Monkey\Functions\when('get_option')->justReturn('');
        \Brain\Monkey\Functions\when('update_option')->justReturn(true);

        $out = $this->chat(['/tools', '/exit'], buildEngine: false);

        self::assertStringContainsString('Provider: ? | Model: ? | Tools: 0', $out);
        self::assertStringContainsString('0 tools: none', $out);
    }

    public function test_the_summary_line_lists_each_tool_the_turn_used_once(): void
    {
        $command = new ChatCommand(fopen('php://memory', 'r'), tmpfile(), isTerminal: true, decorated: false);
        $turn = new ConversationTurn(
            new AgentResponse(text: 'ok', provider: 'scripted', model: 'scripted-model', iterations: 2, toolsCalled: ['wp_posts', 'wp_posts', 'wp_users']),
            Conversation::start(),
        );

        $summary = (new \ReflectionMethod($command, 'turnSummary'))->invoke($command, $turn);

        self::assertStringContainsString('| Tools: wp_posts, wp_users | Conversation: '.$turn->conversation->id, $summary);
    }

    public function test_a_turn_saves_its_tool_calls_into_the_stored_history(): void
    {
        $memory = new ArrayMemory;
        $this->provider = new ScriptedProvider(static function (): void {
            HookRegistry::fire(LifecycleEvent::ToolAfter->value, [
                'tool_name' => 'wp_query',
                'tool_input' => ['post_type' => 'post'],
                'tool_result' => '{"posts":[]}',
            ]);
        });
        $this->useEngine($this->provider, memory: $memory);

        $out = $this->chat(['how many posts?', '/exit'], buildEngine: false);
        $history = $memory->get($this->conversationId($out), 'conversations')['history'] ?? [];
        $toolRows = array_values(array_filter($history, static fn (array $row): bool => ($row['role'] ?? '') === 'tool'));

        self::assertCount(1, $toolRows);
        self::assertSame('wp_query', $toolRows[0]['tool_name']);
        self::assertSame('{"posts":[]}', $toolRows[0]['content']);
    }

    public function test_a_second_turn_keeps_the_tool_rows_of_the_first(): void
    {
        $memory = new ArrayMemory;
        $this->provider = new ScriptedProvider(static function (): void {
            HookRegistry::fire(LifecycleEvent::ToolAfter->value, [
                'tool_name' => 'wp_query',
                'tool_input' => ['post_type' => 'post'],
                'tool_result' => '{"posts":[]}',
            ]);
        });
        $this->useEngine($this->provider, memory: $memory);

        $out = $this->chat(['how many posts?', 'and pages?', '/exit'], buildEngine: false);
        $history = $memory->get($this->conversationId($out), 'conversations')['history'] ?? [];

        self::assertSame(['user', 'tool', 'assistant', 'user', 'tool', 'assistant'], array_column($history, 'role'));
    }

    public function test_with_storage_off_the_session_stays_in_one_conversation(): void
    {
        $this->useEngine($this->provider, saved: ['store_messages' => '0']);

        $out = $this->chat(['first question', 'second question', '/exit'], buildEngine: false);

        self::assertSame(['first question', 'second question'], $this->provider->received);
        self::assertSame(3, substr_count($out, 'Conversation: '.$this->conversationId($out)));
    }
}
