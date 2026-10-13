<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\CLI;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\OpenCart\CLI\ChatCommand;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class ChatCommandSpinnerTest extends TestCase
{
    private ChatCommand $command;

    private mixed $stream = null;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->useOutput(decorated: true);
    }

    protected function tearDown(): void
    {
        $this->invoke('stopSpinner');
        HookRegistry::reset();
    }

    private function useOutput(bool $decorated): void
    {
        $this->stream = tmpfile();
        $this->command = new ChatCommand(
            fn (): ClawInterface => $this->createMock(ClawInterface::class),
            sys_get_temp_dir().'/phpclaw-oc-spinner-'.uniqid(),
            'phpclaw.php',
            fopen('php://memory', 'r'),
            $this->stream,
            isTerminal: true,
            decorated: $decorated,
        );
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
        $this->invoke('startSpinner');
        $pid = $this->spinnerPid();
        usleep(350000);
        $this->invoke('stopSpinner');

        $written = $this->written();
        self::assertIsInt($pid);
        self::assertGreaterThanOrEqual(2, substr_count($written, 'Thinking...'));
        self::assertStringEndsWith("\r\e[2K", $written);
        self::assertNull($this->spinnerPid());
        self::assertFalse(posix_kill($pid, 0));
    }

    public function test_spinner_starts_only_once_while_shown(): void
    {
        $this->invoke('startSpinner');
        $first = $this->spinnerPid();
        $this->invoke('startSpinner');

        self::assertSame($first, $this->spinnerPid());
    }

    public function test_spinner_stays_off_when_the_output_is_not_a_terminal(): void
    {
        $this->useOutput(decorated: false);

        $this->invoke('startSpinner');
        usleep(150000);
        $this->invoke('stopSpinner');

        self::assertNull($this->spinnerPid());
        self::assertSame('', $this->written());
    }

    public function test_stopping_a_spinner_that_never_started_writes_nothing(): void
    {
        $this->invoke('stopSpinner');

        self::assertSame('', $this->written());
    }

    public function test_a_turn_shows_the_spinner_until_the_reply_and_around_a_tool_call(): void
    {
        $conversation = new Conversation('01OCCHATCONVERSATION00000A', [], new \DateTimeImmutable);
        $claw = $this->createMock(ClawInterface::class);
        $claw->method('streamInConversation')->willReturnCallback(static function (Conversation $c, string $message, callable $onToken): ConversationTurn {
            usleep(250000);
            HookRegistry::fire(LifecycleEvent::ToolBefore->value, ['tool_name' => 'oc_order', 'tool_input' => []]);
            HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'oc_order']);
            usleep(250000);
            $onToken('the answer');

            return new ConversationTurn(new AgentResponse(text: 'the answer', provider: 'ollama', model: 'qwen2.5:7b', iterations: 1), $c);
        });
        (new ReflectionProperty($this->command, 'engine'))->setValue($this->command, $claw);
        (new ReflectionProperty($this->command, 'conversation'))->setValue($this->command, $conversation);

        $this->invoke('watchToolsForSpinner');
        $this->invoke('startToolTrace', fn (string $line) => fwrite($this->stream, $line.PHP_EOL));
        $this->invoke('runTurn', 'how many orders today?');
        $this->invoke('unwatchToolsForSpinner');
        $this->invoke('stopToolTrace');

        $written = $this->written();
        $trace = strpos($written, '> oc_order');
        $answer = strpos($written, 'the answer');
        self::assertNotFalse($trace);
        self::assertNotFalse($answer);
        self::assertLessThan($trace, strpos($written, 'Thinking...'));
        self::assertGreaterThan($trace, strrpos($written, 'Thinking...'));
        self::assertLessThan($answer, strrpos($written, 'Thinking...'));
        self::assertNull($this->spinnerPid());
    }

    public function test_a_tool_finishing_outside_a_turn_does_not_start_the_spinner(): void
    {
        $this->invoke('watchToolsForSpinner');
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'oc_order']);
        $this->invoke('unwatchToolsForSpinner');

        self::assertNull($this->spinnerPid());
        self::assertSame('', $this->written());
    }

    public function test_unwatching_removes_the_spinner_listeners(): void
    {
        $this->invoke('watchToolsForSpinner');
        $this->invoke('unwatchToolsForSpinner');
        (new ReflectionProperty($this->command, 'turnRunning'))->setValue($this->command, true);
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'oc_order']);

        self::assertNull($this->spinnerPid());
    }

    public function test_unwatching_before_watching_leaves_the_listeners_alone(): void
    {
        $before = HookRegistry::count();

        $this->invoke('unwatchToolsForSpinner');

        self::assertSame($before, HookRegistry::count());
    }
}
