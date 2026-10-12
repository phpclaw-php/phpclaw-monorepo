<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Commands;

require_once __DIR__.'/../Fixtures/drush-commands-stub.php';

use PhpClaw\Drupal\Commands\PhpClawChatCommands;
use PhpClaw\Drupal\DrupalConsole;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BuildsAgentContext;
use PhpClaw\Drupal\Tests\Unit\Fixtures\ScriptedProvider;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PhpClawChatCommandsTest extends TestCase
{
    use BuildsAgentContext;

    private ScriptedProvider $provider;

    private ArrayMemory $memory;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->provider = new ScriptedProvider;
        $this->memory = new ArrayMemory;
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        ProviderRegistry::reset();
        MemoryRegistry::reset();
    }

    private function chat(array $lines, array $options = [], bool $interactive = true, array $settings = []): array
    {
        $command = new PhpClawChatCommands($this->agentContext($settings, $this->provider, $this->memory));

        $stream = fopen('php://memory', 'r+');
        fwrite($stream, implode(PHP_EOL, $lines).($lines === [] ? '' : PHP_EOL));
        rewind($stream);
        $input = new ArrayInput([]);
        $input->setStream($stream);
        $input->setInteractive($interactive);
        $output = new BufferedOutput;
        $command->setInput($input);
        $command->setOutput($output);

        $code = $command->chat($options + ['conv-id' => null, 'continue' => false]);

        return [$code, (string) preg_replace('/\s+/', ' ', str_replace("\n // ", ' ', $output->fetch()))];
    }

    private function conversationId(string $out): string
    {
        preg_match('/Conversation: ([0-9A-Z]{26})/', $out, $m);

        return $m[1] ?? '';
    }

    public function test_chat_runs_two_turns_in_one_conversation_with_a_summary_line(): void
    {
        [$code, $out] = $this->chat(['first question', 'second question', '/exit']);

        self::assertSame(0, $code);
        self::assertStringContainsString('reply to first question', $out);
        self::assertStringContainsString('reply to second question', $out);
        self::assertSame(['first question', 'second question'], $this->provider->received);
        self::assertSame(3, substr_count($out, 'Conversation: '.$this->conversationId($out)));
        self::assertStringContainsString('Provider: scripted | Model: scripted-model', $out);
    }

    public function test_chat_shows_the_banner_without_a_version_and_the_resume_line(): void
    {
        [, $out] = $this->chat(['/exit']);

        self::assertStringContainsString('AI agents for Drupal', $out);
        self::assertDoesNotMatchRegularExpression('/v\d+\.\d+\.\d+/', $out);
        self::assertStringContainsString('Resume with: drush phpclaw:chat --conv-id='.$this->conversationId($out), $out);
    }

    public function test_chat_ends_at_the_end_of_input_like_ctrl_d(): void
    {
        [$code, $out] = $this->chat(['only question']);

        self::assertSame(0, $code);
        self::assertSame(['only question'], $this->provider->received);
        self::assertStringContainsString('Resume with: drush phpclaw:chat --conv-id=', $out);
    }

    public function test_chat_refuses_to_start_without_a_terminal(): void
    {
        [$code, $out] = $this->chat(['hi'], interactive: false);

        self::assertSame(1, $code);
        self::assertStringContainsString('phpclaw:chat needs an interactive terminal. For one message use: drush phpclaw:run "<message>"', $out);
        self::assertSame([], $this->provider->received);
    }

    public function test_chat_refuses_when_the_agent_cannot_be_built(): void
    {
        [$code, $out] = $this->chat(['hi'], settings: ['provider' => 'no-such-provider-slug']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Agent unavailable. Check the provider and API key in Settings.', $out);
    }

    public function test_chat_ignores_an_empty_line_and_never_sends_a_slash_command(): void
    {
        [, $out] = $this->chat(['', '   ', '/deploy now', 'what does a/b mean?', '/exit']);

        self::assertStringContainsString('Unknown command /deploy. Type /help for the list.', $out);
        self::assertSame(['what does a/b mean?'], $this->provider->received);
    }

    public function test_slash_help_lists_every_command(): void
    {
        [, $out] = $this->chat(['/help', '/exit']);

        foreach (['/help', '/tools', '/model <name>', '/provider <slug>', '/new', '/conv', '/quiet', '/clear', '/exit'] as $slash) {
            self::assertStringContainsString($slash, $out);
        }
    }

    public function test_slash_tools_lists_the_agent_tools(): void
    {
        [, $out] = $this->chat(['/tools', '/exit']);

        self::assertMatchesRegularExpression('/\d+ tools: .*shell_exec/', $out);
    }

    public function test_slash_new_and_conv_switch_to_a_new_conversation_and_save_it(): void
    {
        [, $out] = $this->chat(['/new', '/conv', '/exit']);

        preg_match('/New conversation: ([0-9A-Z]{26})/', $out, $m);
        self::assertNotEmpty($m[1] ?? '');
        self::assertStringContainsString('Conversation: '.$m[1], $out);
        self::assertSame($m[1], $this->stateValues[DrupalConsole::LAST_CONVERSATION_STATE_KEY]);
    }

    public function test_slash_model_and_provider_switch_for_the_session_only(): void
    {
        ProviderRegistry::register('other', fn () => $this->provider);

        [, $out] = $this->chat(['/model bigger-model', '/provider other', 'hello', '/exit']);

        self::assertStringContainsString('Model for this session: bigger-model', $out);
        self::assertStringContainsString('Provider: scripted | Model: bigger-model', $out);
        self::assertStringContainsString('Provider for this session: other', $out);
        self::assertStringContainsString('Provider: other | Model: bigger-model', $out);
        self::assertSame(['hello'], $this->provider->received);
    }

    public function test_slash_model_and_provider_without_a_value_print_the_usage(): void
    {
        [, $out] = $this->chat(['/model', '/provider', '/exit']);

        self::assertStringContainsString('Usage: /model <name>', $out);
        self::assertStringContainsString('Usage: /provider <slug>', $out);
    }

    public function test_a_provider_that_cannot_be_built_names_the_cause_and_keeps_the_engine(): void
    {
        [, $out] = $this->chat(['/provider nope', 'still here', '/exit']);

        self::assertStringContainsString('Cannot switch to provider nope: the provider name is unknown or its API key is not saved in phpClaw settings.', $out);
        self::assertStringNotContainsString('Provider for this session: nope', $out);
        self::assertSame(['still here'], $this->provider->received);
    }

    public function test_a_blocked_message_names_the_guard_reason_and_returns_to_the_prompt(): void
    {
        $this->provider = new ScriptedProvider(static function (string $message): void {
            if ($message === 'bad') {
                throw new GuardException('Prompt injection detected: message contains blocked pattern.');
            }
        });

        [$code, $out] = $this->chat(['bad', 'good', '/exit']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Your message was blocked by a security guard: Prompt injection detected: message contains blocked pattern.', $out);
        self::assertStringContainsString('reply to good', $out);
    }

    public function test_any_other_agent_failure_names_only_the_class_and_the_session_goes_on(): void
    {
        $this->provider = new ScriptedProvider(static function (string $message): void {
            if ($message === 'break') {
                throw new ToolException('disk full at /var/secret');
            }
        });

        [, $out] = $this->chat(['break', 'again', '/exit']);

        self::assertStringContainsString('The agent run failed: PhpClaw\Exceptions\ToolException', $out);
        self::assertStringNotContainsString('/var/secret', $out);
        self::assertStringContainsString('reply to again', $out);
    }

    public function test_the_tool_trace_is_on_by_default_and_quiet_turns_it_off_and_on(): void
    {
        $this->provider = new ScriptedProvider(static function (string $message): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls '.$message]]);
        });

        [, $out] = $this->chat(['first', '/quiet', 'second', '/quiet', 'third', '/exit']);

        self::assertStringContainsString('> shell_exec: ls first', $out);
        self::assertStringContainsString('Tool trace off.', $out);
        self::assertStringNotContainsString('> shell_exec: ls second', $out);
        self::assertStringContainsString('Tool trace on.', $out);
        self::assertStringContainsString('> shell_exec: ls third', $out);
    }

    public function test_slash_clear_clears_the_screen(): void
    {
        $command = new PhpClawChatCommands($this->agentContext([], $this->provider, $this->memory));
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "/clear\n/exit\n");
        rewind($stream);
        $input = new ArrayInput([]);
        $input->setStream($stream);
        $output = new BufferedOutput;
        $command->setInput($input);
        $command->setOutput($output);

        $command->chat(['conv-id' => null, 'continue' => false]);

        self::assertStringContainsString("\033[2J\033[H", $output->fetch());
    }

    public function test_continue_opens_the_last_conversation(): void
    {
        [, $first] = $this->chat(['hello', '/exit']);
        [, $second] = $this->chat(['/exit'], ['continue' => true]);

        self::assertSame($this->conversationId($first), $this->conversationId($second));
    }

    public function test_continue_without_a_saved_conversation_starts_a_new_one(): void
    {
        [, $out] = $this->chat(['/exit'], ['continue' => true]);

        self::assertStringContainsString('No previous conversation to continue; starting a new one.', $out);
    }

    public function test_an_unknown_conv_id_says_a_new_conversation_was_started(): void
    {
        [, $out] = $this->chat(['/exit'], ['conv-id' => '01DRNOSUCHCONVERSATION00AA']);

        self::assertStringContainsString('No stored conversation 01DRNOSUCHCONVERSATION00AA; started '.$this->conversationId($out).'.', $out);
    }

    public function test_the_trace_and_spinner_listeners_are_removed_when_the_session_ends(): void
    {
        $command = new PhpClawChatCommands($this->agentContext([], $this->provider, $this->memory));
        $before = HookRegistry::count();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "/exit\n");
        rewind($stream);
        $input = new ArrayInput([]);
        $input->setStream($stream);
        $command->setInput($input);
        $command->setOutput(new BufferedOutput);

        $command->chat(['conv-id' => null, 'continue' => false]);

        self::assertSame($before, HookRegistry::count());
    }
}
