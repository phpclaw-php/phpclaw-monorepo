<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Command;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Symfony\Command\ChatCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ChatCommandSpinnerTest extends TestCase
{
    private ChatCommand $command;

    private SymfonyStyle $io;

    private mixed $stream = null;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->command = new ChatCommand($this->createMock(PhpClawInterface::class));
        $this->useOutput(decorated: true);
    }

    protected function tearDown(): void
    {
        $this->invoke('stopSpinner', $this->io);
        HookRegistry::reset();
    }

    private function useOutput(bool $decorated): void
    {
        $this->stream = tmpfile();
        $this->io = new SymfonyStyle(new ArrayInput([]), new StreamOutput($this->stream, decorated: $decorated));
    }

    private function written(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($this->command, $method))->invoke($this->command, ...$arguments);
    }

    private function spinnerPid(): ?int
    {
        return (new ReflectionProperty($this->command, 'spinnerPid'))->getValue($this->command);
    }

    public function test_spinner_animates_until_stopped_then_clears_its_line(): void
    {
        $this->invoke('startSpinner', $this->io);
        $pid = $this->spinnerPid();
        usleep(350000);
        $this->invoke('stopSpinner', $this->io);

        $written = $this->written();
        $this->assertIsInt($pid);
        $this->assertGreaterThanOrEqual(2, substr_count($written, 'Thinking...'));
        $this->assertStringEndsWith("\r\e[2K", $written);
        $this->assertNull($this->spinnerPid());
        $this->assertFalse(posix_kill($pid, 0));
    }

    public function test_spinner_starts_only_once_while_shown(): void
    {
        $this->invoke('startSpinner', $this->io);
        $first = $this->spinnerPid();
        $this->invoke('startSpinner', $this->io);

        $this->assertSame($first, $this->spinnerPid());
    }

    public function test_spinner_stays_off_when_the_output_is_not_a_terminal(): void
    {
        $this->useOutput(decorated: false);

        $this->invoke('startSpinner', $this->io);
        usleep(150000);
        $this->invoke('stopSpinner', $this->io);

        $this->assertNull($this->spinnerPid());
        $this->assertSame('', $this->written());
    }

    public function test_stopping_a_spinner_that_never_started_writes_nothing(): void
    {
        $this->invoke('stopSpinner', $this->io);

        $this->assertSame('', $this->written());
    }

    public function test_a_turn_shows_the_spinner_until_the_reply_and_around_a_tool_call(): void
    {
        $conversation = new Conversation('01CHATCONVERSATION000000A', [], new \DateTimeImmutable);
        $claw = $this->createMock(PhpClawInterface::class);
        $claw->method('streamInConversation')->willReturnCallback(static function (Conversation $c, string $message, callable $onToken): ConversationTurn {
            usleep(250000);
            HookRegistry::fire(LifecycleEvent::ToolBefore->value, ['tool_name' => 'project_info', 'tool_input' => []]);
            HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'project_info']);
            usleep(250000);
            $onToken('the answer');

            return new ConversationTurn(new AgentResponse(text: 'the answer', provider: 'anthropic', model: 'claude-haiku-4-5-20251001', iterations: 1), $c);
        });
        $this->command = new ChatCommand($claw);
        (new ReflectionProperty($this->command, 'conversation'))->setValue($this->command, $conversation);

        $this->invoke('watchToolsForSpinner', $this->io);
        $this->invoke('startToolTrace', $this->io);
        $this->invoke('runTurn', $this->io, 'what is the project name?');
        $this->invoke('unwatchToolsForSpinner');
        $this->invoke('stopToolTrace');

        $written = $this->written();
        $trace = strpos($written, '> project_info');
        $answer = strpos($written, 'the answer');
        $this->assertNotFalse($trace);
        $this->assertNotFalse($answer);
        $this->assertLessThan($trace, strpos($written, 'Thinking...'));
        $this->assertGreaterThan($trace, strrpos($written, 'Thinking...'));
        $this->assertLessThan($answer, strrpos($written, 'Thinking...'));
        $this->assertNull($this->spinnerPid());
    }

    public function test_a_tool_finishing_outside_a_turn_does_not_start_the_spinner(): void
    {
        $this->invoke('watchToolsForSpinner', $this->io);
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'project_info']);
        $this->invoke('unwatchToolsForSpinner');

        $this->assertNull($this->spinnerPid());
        $this->assertSame('', $this->written());
    }

    public function test_unwatching_removes_the_spinner_listeners(): void
    {
        $this->invoke('watchToolsForSpinner', $this->io);
        $this->invoke('unwatchToolsForSpinner');
        (new ReflectionProperty($this->command, 'turnRunning'))->setValue($this->command, true);
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'project_info']);

        $this->assertNull($this->spinnerPid());
    }
}
