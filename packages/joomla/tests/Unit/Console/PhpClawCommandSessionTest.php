<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Console;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Joomla\Component\Administrator\Console\ChatCommand;
use PhpClaw\Joomla\Component\Administrator\Console\PhpClawCommand;
use PhpClaw\Joomla\Component\Administrator\Exceptions\ConversationAccessDeniedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

final class PhpClawCommandSessionTest extends TestCase
{
    private const CONVERSATION_ID = '01JMRUNCONVERSATION000000A';

    private string $storage = '';

    private array $opened = [];

    private int $exitCode = 0;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->opened = [];
        $this->storage = sys_get_temp_dir().'/phpclaw-jm-run-'.uniqid();
        mkdir($this->storage, 0777, true);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        @unlink($this->storage.'/.last-conversation');
        @rmdir($this->storage);
    }

    private function agent(?\Closure $onSend = null): ClawInterface&MockObject
    {
        $mock = $this->createMock(ClawInterface::class);
        $mock->method('conversation')->willReturnCallback(function (string $id = ''): Conversation {
            $this->opened[] = $id;

            return new Conversation($id === '01JMMISSING00000000000000A' || $id === '' ? self::CONVERSATION_ID : $id, [], new \DateTimeImmutable);
        });
        $turn = function (Conversation $c, string $message) use ($onSend): ConversationTurn {
            if ($onSend !== null) {
                $onSend($message);
            }

            return new ConversationTurn(new AgentResponse(text: 'reply to '.$message, provider: 'ollama', model: 'qwen2.5:7b', iterations: 1, inputTokens: 12, outputTokens: 3, toolsCalled: ['joomla_articles', 'joomla_articles']), $c);
        };
        $mock->method('sendInConversation')->willReturnCallback($turn);
        $mock->method('streamInConversation')->willReturnCallback(static function (Conversation $c, string $message, callable $onToken) use ($turn): ConversationTurn {
            $onToken('streamed ');

            return $turn($c, $message);
        });

        return $mock;
    }

    private function runCommand(ClawInterface $agent, array $input): string
    {
        $command = new PhpClawCommand(static fn (): ClawInterface => $agent, $this->storage);
        $output = new BufferedOutput;

        $this->exitCode = $command->execute(new ArrayInput($input + ['message' => 'hello']), $output);

        return $output->fetch();
    }

    public function test_a_run_prints_the_reply_and_the_summary_line(): void
    {
        $out = $this->runCommand($this->agent(), []);

        self::assertSame(0, $this->exitCode);
        self::assertStringContainsString('reply to hello', $out);
        self::assertStringContainsString('Provider: ollama | Model: qwen2.5:7b | Tokens: 12→3 | Tools: joomla_articles | Conversation: '.self::CONVERSATION_ID, $out);
    }

    public function test_a_stream_run_prints_the_tokens_and_the_summary_line(): void
    {
        $out = $this->runCommand($this->agent(), ['--stream' => true]);

        self::assertStringContainsString('streamed ', $out);
        self::assertStringContainsString('| Conversation: '.self::CONVERSATION_ID, $out);
    }

    public function test_a_stream_run_continues_the_given_conversation(): void
    {
        $out = $this->runCommand($this->agent(), ['--stream' => true, '--conv-id' => '01JMGIVEN00000000000000000']);

        self::assertSame(['01JMGIVEN00000000000000000'], $this->opened);
        self::assertStringContainsString('| Conversation: 01JMGIVEN00000000000000000', $out);
    }

    public function test_a_run_saves_its_conversation_for_continue(): void
    {
        $this->runCommand($this->agent(), []);

        self::assertSame(self::CONVERSATION_ID, file_get_contents($this->storage.'/.last-conversation'));
    }

    public function test_continue_reuses_the_last_saved_conversation(): void
    {
        file_put_contents($this->storage.'/.last-conversation', '01JMLAST000000000000000000');

        $out = $this->runCommand($this->agent(), ['--continue' => true]);

        self::assertSame(['01JMLAST000000000000000000'], $this->opened);
        self::assertStringContainsString('| Conversation: 01JMLAST000000000000000000', $out);
    }

    public function test_continue_with_nothing_saved_starts_a_new_conversation(): void
    {
        $out = $this->runCommand($this->agent(), ['--continue' => true]);

        self::assertSame([''], $this->opened);
        self::assertStringContainsString('No previous conversation to continue; starting a new one.', $out);
    }

    public function test_an_unknown_conv_id_says_a_new_conversation_was_started(): void
    {
        $out = $this->runCommand($this->agent(), ['--conv-id' => '01JMMISSING00000000000000A']);

        self::assertStringContainsString('No stored conversation 01JMMISSING00000000000000A; started '.self::CONVERSATION_ID.'.', $out);
    }

    public function test_a_conversation_the_console_may_not_read_prints_one_line_and_starts_a_new_one(): void
    {
        $mock = $this->agent();
        $guarded = $this->createMock(ClawInterface::class);
        $guarded->method('conversation')->willReturnCallback(static function (string $id = ''): Conversation {
            if ($id !== '') {
                throw new ConversationAccessDeniedException('owner 42 at /var/secret');
            }

            return new Conversation(self::CONVERSATION_ID, [], new \DateTimeImmutable);
        });
        $guarded->method('sendInConversation')->willReturnCallback(static fn (Conversation $c, string $m): ConversationTurn => $mock->sendInConversation($c, $m));

        $out = $this->runCommand($guarded, ['--conv-id' => '01JMSOMEONEELSE0000000000A']);

        self::assertSame(0, $this->exitCode);
        self::assertStringContainsString('Conversation 01JMSOMEONEELSE0000000000A is not available to the console; started '.self::CONVERSATION_ID.'.', $out);
        self::assertStringNotContainsString('/var/secret', $out);
    }

    public function test_trace_prints_one_line_per_tool_call_and_never_the_result(): void
    {
        $agent = $this->agent(static function (): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls -la']]);
            HookRegistry::fire('tool.after', ['tool_name' => 'shell_exec', 'result' => 'SECRET-RESULT']);
        });

        $out = $this->runCommand($agent, ['--trace' => true]);

        self::assertStringContainsString('  > shell_exec: ls -la', $out);
        self::assertStringNotContainsString('SECRET-RESULT', $out);
    }

    public function test_trace_summarises_each_tool_kind_and_cuts_a_long_input(): void
    {
        $agent = $this->agent(static function (): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'file_write', 'tool_input' => ['path' => 'notes.txt', 'content' => 'abcd']]);
            HookRegistry::fire('tool.before', ['tool_name' => 'file_read', 'tool_input' => ['file' => 'config.php']]);
            HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => str_repeat('x', 90)."\nrm"]]);
            HookRegistry::fire('tool.before', ['tool_name' => 'joomla_articles', 'tool_input' => ['limit' => 5]]);
        });

        $out = $this->runCommand($agent, ['--trace' => true]);

        self::assertStringContainsString('  > file_write: notes.txt (4 bytes)', $out);
        self::assertStringContainsString('  > file_read: config.php', $out);
        self::assertStringContainsString('  > shell_exec: '.str_repeat('x', 80).'...', $out);
        self::assertStringNotContainsString('rm', $out);
        self::assertMatchesRegularExpression('/  > joomla_articles\n/', $out);
    }

    public function test_without_storage_folder_the_last_conversation_goes_to_the_component_storage(): void
    {
        $file = JPATH_ADMINISTRATOR.'/components/com_phpclaw/storage/.last-conversation';
        $agent = $this->agent();
        $command = new PhpClawCommand(static fn (): ClawInterface => $agent);

        $command->execute(new ArrayInput(['message' => 'hello']), new BufferedOutput);

        self::assertSame(self::CONVERSATION_ID, file_get_contents($file));
        unlink($file);
    }

    public function test_without_trace_no_tool_line_is_printed(): void
    {
        $agent = $this->agent(static function (): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls -la']]);
        });

        $out = $this->runCommand($agent, []);

        self::assertStringNotContainsString('> shell_exec', $out);
    }

    public function test_the_trace_listener_is_removed_after_a_run(): void
    {
        $before = HookRegistry::count();

        $this->runCommand($this->agent(), ['--trace' => true]);

        self::assertSame($before, HookRegistry::count());
    }

    public function test_any_other_agent_failure_prints_one_line_and_saves_nothing(): void
    {
        $agent = $this->agent(static function (): void {
            throw new ToolException('disk full at /var/secret');
        });
        $before = HookRegistry::count();

        $out = $this->runCommand($agent, ['--trace' => true]);

        self::assertSame(1, $this->exitCode);
        self::assertStringContainsString('COM_PHPCLAW_ERROR_INTERNAL', $out);
        self::assertStringNotContainsString('/var/secret', $out);
        self::assertFileDoesNotExist($this->storage.'/.last-conversation');
        self::assertSame($before, HookRegistry::count());
    }

    public function test_both_commands_describe_themselves_in_the_command_list(): void
    {
        self::assertSame('Send a message to the PhpClaw AI agent.', (new PhpClawCommand(fn (): ClawInterface => $this->agent(), $this->storage))->getDescription());
        self::assertSame('Open an interactive phpClaw agent session.', (new ChatCommand(fn (): ClawInterface => $this->agent(), $this->storage))->getDescription());
    }

    public function test_the_command_declares_the_continue_and_trace_options(): void
    {
        $definition = (new PhpClawCommand(fn (): ClawInterface => $this->agent(), $this->storage))->getDefinition();

        self::assertTrue($definition->hasOption('continue'));
        self::assertTrue($definition->hasOption('trace'));
    }
}
