<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Console;

use Illuminate\Console\OutputStyle;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use Orchestra\Testbench\TestCase;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Laravel\Console\ChatCommand;
use PhpClaw\Laravel\PhpClawServiceProvider;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

final class ChatCommandTerminalTest extends TestCase
{
    private ChatCommand $command;

    private mixed $stream = null;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.provider', 'anthropic');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();
        (new ReflectionProperty(Prompt::class, 'shouldFallback'))->setValue(null, false);
        $this->command = $this->app->make(ChatCommand::class);
        $this->command->setLaravel($this->app);
        $this->useOutput(decorated: true);
    }

    protected function tearDown(): void
    {
        $this->invoke('stopSpinner');
        HookRegistry::reset();
        parent::tearDown();
    }

    private function useOutput(bool $decorated): void
    {
        $this->stream = tmpfile();
        $this->command->setOutput(new OutputStyle(new ArrayInput([]), new StreamOutput($this->stream, decorated: $decorated)));
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

    public function test_slash_menu_lists_every_command_name_after_a_slash(): void
    {
        $this->assertSame(
            ['/help', '/tools', '/model', '/provider', '/new', '/conv', '/quiet', '/clear', '/exit'],
            $this->invoke('slashSuggestions', '/'),
        );
    }

    public function test_slash_menu_narrows_to_the_typed_prefix(): void
    {
        $this->assertSame(['/model'], $this->invoke('slashSuggestions', '/m'));
        $this->assertSame(['/conv', '/clear'], $this->invoke('slashSuggestions', '/c'));
    }

    public function test_slash_menu_is_empty_for_a_message(): void
    {
        $this->assertSame([], $this->invoke('slashSuggestions', ''));
        $this->assertSame([], $this->invoke('slashSuggestions', 'hello'));
    }

    public function test_slash_menu_is_empty_once_an_argument_is_typed(): void
    {
        $this->assertSame([], $this->invoke('slashSuggestions', '/model qwen'));
    }

    public function test_slash_menu_submits_the_highlighted_command(): void
    {
        Prompt::fake(['/', 'q', Key::DOWN, Key::ENTER]);

        $this->assertSame('/quiet', $this->invoke('askWithSlashMenu'));
    }

    public function test_slash_menu_shows_all_nine_commands_at_once(): void
    {
        Prompt::fake(['/', Key::ENTER]);

        $this->invoke('askWithSlashMenu');

        $screen = Prompt::content();
        foreach (['/help', '/tools', '/model', '/provider', '/new', '/conv', '/quiet', '/clear', '/exit'] as $name) {
            $this->assertStringContainsString($name, $screen);
        }
    }

    public function test_slash_menu_returns_a_typed_message(): void
    {
        Prompt::fake(['h', 'i', Key::ENTER]);

        $this->assertSame('hi', $this->invoke('askWithSlashMenu'));
    }

    public function test_ctrl_d_on_an_empty_line_ends_input(): void
    {
        Prompt::fake(["\x04"]);

        $this->assertNull($this->invoke('askWithSlashMenu'));
    }

    public function test_ctrl_d_after_text_is_ignored(): void
    {
        Prompt::fake(['o', 'k', "\x04", Key::ENTER]);

        $this->assertSame('ok', $this->invoke('askWithSlashMenu'));
    }

    public function test_slash_menu_is_used_only_on_a_command_line(): void
    {
        $this->command->setInput(new ArgvInput(['artisan', 'phpclaw:chat']));
        $this->assertSame(PHP_OS_FAMILY !== 'Windows', $this->invoke('hasSlashMenu'));

        $this->command->setInput(new ArrayInput([]));
        $this->assertFalse($this->invoke('hasSlashMenu'));
    }

    public function test_spinner_animates_until_stopped_then_clears_its_line(): void
    {
        $this->invoke('startSpinner');
        $pid = $this->spinnerPid();
        usleep(350000);
        $this->invoke('stopSpinner');

        $written = $this->written();
        $this->assertIsInt($pid);
        $this->assertGreaterThanOrEqual(2, substr_count($written, 'Thinking...'));
        $this->assertStringEndsWith("\r\e[2K", $written);
        $this->assertNull($this->spinnerPid());
        $this->assertFalse(posix_kill($pid, 0));
    }

    public function test_spinner_starts_only_once_while_shown(): void
    {
        $this->invoke('startSpinner');
        $first = $this->spinnerPid();
        $this->invoke('startSpinner');

        $this->assertSame($first, $this->spinnerPid());
    }

    public function test_spinner_stays_off_when_the_output_is_not_a_terminal(): void
    {
        $this->useOutput(decorated: false);

        $this->invoke('startSpinner');
        usleep(150000);
        $this->invoke('stopSpinner');

        $this->assertNull($this->spinnerPid());
        $this->assertSame('', $this->written());
    }

    public function test_stopping_a_spinner_that_never_started_writes_nothing(): void
    {
        $this->invoke('stopSpinner');

        $this->assertSame('', $this->written());
    }

    public function test_a_turn_shows_the_spinner_until_the_reply_and_around_a_tool_call(): void
    {
        $conversation = new Conversation('01CHATCONVERSATION000000A', [], new \DateTimeImmutable);
        $claw = $this->createMock(PhpClawInterface::class);
        $claw->method('streamInConversation')->willReturnCallback(static function (Conversation $c, string $message, callable $onToken): ConversationTurn {
            usleep(250000);
            HookRegistry::fire(LifecycleEvent::ToolBefore->value, ['tool_name' => 'route_list', 'tool_input' => []]);
            HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'route_list']);
            usleep(250000);
            $onToken('the answer');

            return new ConversationTurn(new AgentResponse(text: 'the answer', provider: 'anthropic', model: 'claude-haiku-4-5-20251001', iterations: 1), $c);
        });
        (new ReflectionProperty($this->command, 'claw'))->setValue($this->command, $claw);
        (new ReflectionProperty($this->command, 'conversation'))->setValue($this->command, $conversation);

        $this->invoke('watchToolsForSpinner');
        $this->invoke('startToolTrace');
        $this->invoke('runTurn', 'how many routes?');
        $this->invoke('unwatchToolsForSpinner');
        $this->invoke('stopToolTrace');

        $written = $this->written();
        $trace = strpos($written, '> route_list');
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
        $this->invoke('watchToolsForSpinner');
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'route_list']);
        $this->invoke('unwatchToolsForSpinner');

        $this->assertNull($this->spinnerPid());
        $this->assertSame('', $this->written());
    }

    public function test_unwatching_removes_the_spinner_listeners(): void
    {
        $this->invoke('watchToolsForSpinner');
        $this->invoke('unwatchToolsForSpinner');
        (new ReflectionProperty($this->command, 'turnRunning'))->setValue($this->command, true);
        HookRegistry::fire(LifecycleEvent::ToolAfter->value, ['tool_name' => 'route_list']);

        $this->assertNull($this->spinnerPid());
    }
}
