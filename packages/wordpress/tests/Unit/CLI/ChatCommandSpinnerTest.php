<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\CLI;

require_once __DIR__.'/Fixtures/wp-cli-stub.php';

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\WordPress\CLI\ChatCommand;
use PhpClaw\WordPress\Plugin;
use PhpClaw\WordPress\Tests\Unit\CLI\Fixtures\BuildsCliEngine;
use PhpClaw\WordPress\Tests\Unit\CLI\Fixtures\ScriptedProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class ChatCommandSpinnerTest extends TestCase
{
    use BuildsCliEngine;
    use MockeryPHPUnitIntegration;

    private ChatCommand $command;

    private mixed $stream = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        HookRegistry::reset();
        $this->useOutput(decorated: true);
    }

    protected function tearDown(): void
    {
        $this->invoke('stopSpinner');
        HookRegistry::reset();
        ProviderRegistry::reset();
        MemoryRegistry::reset();
        (new \ReflectionClass(Plugin::class))->getProperty('instance')->setValue(null, null);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function useOutput(bool $decorated): void
    {
        $this->stream = tmpfile();
        $this->command = new ChatCommand(fopen('php://memory', 'r'), $this->stream, isTerminal: true, decorated: $decorated);
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
        $engine = $this->useEngine(new ScriptedProvider(static function (): void {
            usleep(250000);
            HookRegistry::fire(LifecycleEvent::ToolBefore->value, ['tool_name' => 'wp_posts', 'tool_input' => []]);
            HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'wp_posts']);
            usleep(250000);
        }));
        (new ReflectionProperty($this->command, 'engine'))->setValue($this->command, $engine);
        (new ReflectionProperty($this->command, 'conversation'))->setValue($this->command, $engine->conversation());

        $this->invoke('watchToolsForSpinner');
        $this->invoke('startToolTrace', fn (string $line) => fwrite($this->stream, $line.PHP_EOL));
        $this->invoke('runTurn', 'how many posts?');
        $this->invoke('unwatchToolsForSpinner');
        $this->invoke('stopToolTrace');

        $written = $this->written();
        $trace = strpos($written, '> wp_posts');
        $answer = strpos($written, 'reply to how many posts?');
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
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'wp_posts']);
        $this->invoke('unwatchToolsForSpinner');

        self::assertNull($this->spinnerPid());
        self::assertSame('', $this->written());
    }

    public function test_unwatching_removes_the_spinner_listeners(): void
    {
        $this->invoke('watchToolsForSpinner');
        $this->invoke('unwatchToolsForSpinner');
        (new ReflectionProperty($this->command, 'turnRunning'))->setValue($this->command, true);
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'wp_posts']);

        self::assertNull($this->spinnerPid());
    }

    public function test_unwatching_before_watching_leaves_the_listeners_alone(): void
    {
        $before = HookRegistry::count();

        $this->invoke('unwatchToolsForSpinner');

        self::assertSame($before, HookRegistry::count());
    }

    public function test_starting_the_trace_twice_keeps_one_listener(): void
    {
        $before = HookRegistry::count(LifecycleEvent::ToolBefore->value);

        $this->invoke('startToolTrace', static fn (string $line) => null);
        $this->invoke('startToolTrace', static fn (string $line) => null);

        self::assertSame($before + 1, HookRegistry::count(LifecycleEvent::ToolBefore->value));
    }
}
