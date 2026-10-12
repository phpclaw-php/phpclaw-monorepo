<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Commands;

require_once __DIR__.'/../Fixtures/drush-commands-stub.php';

use PhpClaw\Drupal\Commands\PhpClawChatCommands;
use PhpClaw\Drupal\PhpClawServiceFactory;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BuildsAgentContext;
use PhpClaw\Drupal\Tests\Unit\Fixtures\ScriptedProvider;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PhpClawChatCommandsSpinnerTest extends TestCase
{
    use BuildsAgentContext;

    private PhpClawChatCommands $command;

    private SymfonyStyle $io;

    private mixed $stream = null;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->command = new PhpClawChatCommands($this->agentContext());
        $this->useOutput(decorated: true);
    }

    protected function tearDown(): void
    {
        $this->invoke('stopSpinner', $this->io);
        HookRegistry::reset();
        ProviderRegistry::reset();
        MemoryRegistry::reset();
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
        $context = $this->agentContext(provider: new ScriptedProvider(static function (): void {
            usleep(250000);
            HookRegistry::fire(LifecycleEvent::ToolBefore->value, ['tool_name' => 'drupal_modules', 'tool_input' => []]);
            HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'drupal_modules']);
            usleep(250000);
        }));
        $this->command = new PhpClawChatCommands($context);
        $claw = PhpClawServiceFactory::createForSession($context);
        (new ReflectionProperty($this->command, 'claw'))->setValue($this->command, $claw);
        (new ReflectionProperty($this->command, 'conversation'))->setValue($this->command, $claw->conversation());

        $this->invoke('watchToolsForSpinner', $this->io);
        $this->invoke('startToolTrace', $this->io);
        $this->invoke('runTurn', $this->io, 'which modules are on?');
        $this->invoke('unwatchToolsForSpinner');
        $this->invoke('stopToolTrace');

        $written = $this->written();
        $trace = strpos($written, '> drupal_modules');
        $answer = strpos($written, 'reply to which modules are on?');
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
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'drupal_modules']);
        $this->invoke('unwatchToolsForSpinner');

        $this->assertNull($this->spinnerPid());
        $this->assertSame('', $this->written());
    }

    public function test_unwatching_removes_the_spinner_listeners(): void
    {
        $this->invoke('watchToolsForSpinner', $this->io);
        $this->invoke('unwatchToolsForSpinner');
        (new ReflectionProperty($this->command, 'turnRunning'))->setValue($this->command, true);
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'drupal_modules']);

        $this->assertNull($this->spinnerPid());
    }

    public function test_unwatching_before_watching_leaves_the_listeners_alone(): void
    {
        $before = HookRegistry::count();

        $this->invoke('unwatchToolsForSpinner');

        $this->assertSame($before, HookRegistry::count());
    }

    public function test_starting_the_trace_twice_keeps_one_listener(): void
    {
        $before = HookRegistry::count(LifecycleEvent::ToolBefore->value);

        $this->invoke('startToolTrace', $this->io);
        $this->invoke('startToolTrace', $this->io);

        $this->assertSame($before + 1, HookRegistry::count(LifecycleEvent::ToolBefore->value));
    }
}
