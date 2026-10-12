<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Commands;

require_once __DIR__.'/../Fixtures/drush-commands-stub.php';

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Drupal\Commands\PhpClawCommands;
use PhpClaw\Drupal\DrupalConsole;
use PhpClaw\Drupal\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BuildsAgentContext;
use PhpClaw\Drupal\Tests\Unit\Fixtures\ScriptedProvider;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\ProviderRegistry;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @runTestsInSeparateProcesses
 *
 * @preserveGlobalState disabled
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PhpClawCommandsTest extends TestCase
{
    use BuildsAgentContext;

    private const DEFAULTS = ['stream' => false, 'conv-id' => null, 'continue' => false, 'trace' => false];

    private BufferedOutput $output;

    private array $errors = [];

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->output = new BufferedOutput;
        $this->errors = [];
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        ProviderRegistry::reset();
        MemoryRegistry::reset();
    }

    private function command(array $settings = [], ?ScriptedProvider $provider = null, ?MemoryInterface $memory = null): PhpClawCommands
    {
        $command = new PhpClawCommands($this->agentContext($settings, $provider, $memory));
        $command->setOutput($this->output);
        $command->setLogger(new class($this->errors) extends AbstractLogger
        {
            public function __construct(private array &$errors) {}

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->errors[] = [$level, (string) $message];
            }
        });

        return $command;
    }

    private function send(PhpClawCommands $command, string $message, array $options = []): array
    {
        $code = $command->run($message, $options + self::DEFAULTS);

        return [$code, $this->output->fetch()];
    }

    private function conversationId(string $out): string
    {
        preg_match('/Conversation: ([0-9A-Z]{26})/', $out, $m);

        return $m[1] ?? '';
    }

    public function test_a_run_prints_the_reply_and_the_summary_line(): void
    {
        [$code, $out] = $this->send($this->command(), 'hello');

        self::assertSame(0, $code);
        self::assertStringContainsString('reply to hello', $out);
        self::assertMatchesRegularExpression('/Provider: scripted \| Model: scripted-model \| Tokens: \d+→\d+ \| Conversation: [0-9A-Z]{26}/', $out);
    }

    public function test_a_stream_run_prints_the_reply_and_the_summary_line(): void
    {
        [$code, $out] = $this->send($this->command(), 'hello', ['stream' => true]);

        self::assertSame(0, $code);
        self::assertStringContainsString('reply to hello', $out);
        self::assertStringContainsString('Provider: scripted | Model: scripted-model', $out);
    }

    public function test_a_run_saves_its_conversation_for_continue(): void
    {
        [, $out] = $this->send($this->command(), 'hello');

        self::assertSame($this->conversationId($out), $this->stateValues[DrupalConsole::LAST_CONVERSATION_STATE_KEY]);
    }

    public function test_continue_resumes_the_last_conversation(): void
    {
        $command = $this->command();
        [, $first] = $this->send($command, 'hello');
        [, $second] = $this->send($command, 'and then?', ['continue' => true]);

        self::assertSame($this->conversationId($first), $this->conversationId($second));
        self::assertStringNotContainsString('No previous conversation', $second);
    }

    public function test_continue_without_a_saved_conversation_starts_a_new_one(): void
    {
        [$code, $out] = $this->send($this->command(), 'hello', ['continue' => true]);

        self::assertSame(0, $code);
        self::assertStringContainsString('No previous conversation to continue; starting a new one.', $out);
    }

    public function test_conv_id_continues_that_conversation_and_wins_over_continue(): void
    {
        $command = $this->command();
        [, $first] = $this->send($command, 'first');
        $this->send($command, 'other');
        [, $third] = $this->send($command, 'back to the first', ['conv-id' => $this->conversationId($first), 'continue' => true]);

        self::assertSame($this->conversationId($first), $this->conversationId($third));
    }

    public function test_an_unknown_conv_id_says_a_new_conversation_was_started(): void
    {
        [, $out] = $this->send($this->command(), 'hello', ['conv-id' => '01DRNOSUCHCONVERSATION00AA']);

        self::assertStringContainsString('No stored conversation 01DRNOSUCHCONVERSATION00AA; started '.$this->conversationId($out).'.', $out);
    }

    public function test_a_conversation_the_console_may_not_read_starts_a_new_one_with_a_clean_line(): void
    {
        $memory = new class implements MemoryInterface
        {
            private ArrayMemory $inner;

            public function __construct()
            {
                $this->inner = new ArrayMemory;
            }

            public function get(string $key, string $namespace = 'default'): mixed
            {
                if ($key === '01DRADMINOWNEDCONVERSATIONA') {
                    throw new ConversationAccessDeniedException;
                }

                return $this->inner->get($key, $namespace);
            }

            public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
            {
                $this->inner->set($key, $value, $namespace, $ttl);
            }

            public function forget(string $key, string $namespace = 'default'): void
            {
                $this->inner->forget($key, $namespace);
            }

            public function flush(string $namespace = 'default'): void
            {
                $this->inner->flush($namespace);
            }

            public function all(string $namespace = 'default'): array
            {
                return $this->inner->all($namespace);
            }

            public function has(string $key, string $namespace = 'default'): bool
            {
                return $this->inner->has($key, $namespace);
            }
        };

        [$code, $out] = $this->send($this->command(memory: $memory), 'hello', ['conv-id' => '01DRADMINOWNEDCONVERSATIONA']);

        self::assertSame(0, $code);
        self::assertStringContainsString('Conversation 01DRADMINOWNEDCONVERSATIONA is not available to the console; started '.$this->conversationId($out).'.', $out);
        self::assertStringNotContainsString('.php', $out);
    }

    public function test_trace_prints_one_line_per_tool_call_and_never_the_result(): void
    {
        $provider = new ScriptedProvider(static function (string $message): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls '.$message]]);
            HookRegistry::fire('tool.after', ['tool_name' => 'shell_exec', 'tool_result' => 'SECRET RESULT BODY']);
        });

        [, $out] = $this->send($this->command(provider: $provider), 'web', ['trace' => true]);

        self::assertStringContainsString('> shell_exec: ls web', $out);
        self::assertStringNotContainsString('SECRET RESULT BODY', $out);
    }

    public function test_without_trace_no_tool_line_is_printed(): void
    {
        $provider = new ScriptedProvider(static function (): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'drupal_modules', 'tool_input' => []]);
        });

        [, $out] = $this->send($this->command(provider: $provider), 'modules');

        self::assertStringNotContainsString('> drupal_modules', $out);
    }

    public function test_the_trace_listener_is_removed_even_when_the_run_fails(): void
    {
        $provider = new ScriptedProvider(static function (): void {
            throw new GuardException('Prompt injection detected in /var/www/secret.txt');
        });
        $command = $this->command(provider: $provider);
        $before = HookRegistry::count();

        [$code] = $this->send($command, 'hello', ['trace' => true]);

        self::assertSame(1, $code);
        self::assertSame($before, HookRegistry::count());
    }

    public function test_a_blocked_message_names_the_guard_reason(): void
    {
        $provider = new ScriptedProvider(static function (): void {
            throw new GuardException("Prompt injection detected: message contains blocked pattern 'ignore previous instructions'.");
        });

        [$code] = $this->send($this->command(provider: $provider), 'hello');

        self::assertSame(1, $code);
        self::assertSame([['error', "Your message was blocked by a security guard: Prompt injection detected: message contains blocked pattern 'ignore previous instructions'."]], $this->errors);
    }

    public function test_an_agent_that_cannot_be_built_fails_with_one_line(): void
    {
        [$code] = $this->send($this->command(['provider' => 'no-such-provider-slug']), 'hello');

        self::assertSame(1, $code);
        self::assertSame([['error', 'Agent unavailable. Check the provider and API key in Settings.']], $this->errors);
    }

    public function test_trace_shows_the_path_and_size_of_a_file_write_and_never_its_content(): void
    {
        $provider = new ScriptedProvider(static function (): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'file_write', 'tool_input' => ['path' => 'web/notes.txt', 'content' => 'private body']]);
            HookRegistry::fire('tool.before', ['tool_name' => 'file_read', 'tool_input' => ['file' => 'web/robots.txt']]);
        });

        [, $out] = $this->send($this->command(provider: $provider), 'files', ['trace' => true]);

        self::assertStringContainsString('> file_write: web/notes.txt (12 bytes)', $out);
        self::assertStringContainsString('> file_read: web/robots.txt', $out);
        self::assertStringNotContainsString('private body', $out);
    }

    public function test_trace_cuts_a_long_command_to_one_line(): void
    {
        $provider = new ScriptedProvider(static function (): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => str_repeat('a', 100)."\nrm -rf"]]);
        });

        [, $out] = $this->send($this->command(provider: $provider), 'run', ['trace' => true]);

        self::assertStringContainsString('> shell_exec: '.str_repeat('a', 80).'...', $out);
        self::assertStringNotContainsString('rm -rf', $out);
    }

    public function test_the_summary_line_lists_each_tool_the_turn_used_once(): void
    {
        $command = $this->command();
        $turn = new ConversationTurn(
            new AgentResponse(text: 'ok', provider: 'scripted', model: 'scripted-model', iterations: 2, toolsCalled: ['drupal_modules', 'drupal_modules', 'drupal_cron']),
            Conversation::start(),
        );

        $summary = (new \ReflectionMethod($command, 'turnSummary'))->invoke($command, $turn);

        self::assertStringContainsString('| Tools: drupal_modules, drupal_cron | Conversation: '.$turn->conversation->id, $summary);
    }
}
