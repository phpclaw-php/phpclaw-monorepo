<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\CLI;

require_once __DIR__.'/Fixtures/wp-cli-stub.php';

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\ProviderRegistry;
use PhpClaw\WordPress\CLI\PhpClawCommand;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Exceptions\ConversationAccessDeniedException;
use PhpClaw\WordPress\Plugin;
use PhpClaw\WordPress\Rest\PhpClawRestController;
use PhpClaw\WordPress\Tests\Unit\CLI\Fixtures\BuildsCliEngine;
use PhpClaw\WordPress\Tests\Unit\CLI\Fixtures\ScriptedProvider;
use PHPUnit\Framework\TestCase;

final class PhpClawCommandSessionTest extends TestCase
{
    use BuildsCliEngine;
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        HookRegistry::reset();
        \WP_CLI::$log = [];
        \WP_CLI::$error = null;
        \WP_CLI::$success = null;
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

    private function send(string $message, array $assocArgs = []): string
    {
        \WP_CLI::$log = [];
        \WP_CLI::$success = null;
        \WP_CLI::$error = null;
        ob_start();
        (new PhpClawCommand)([$message], $assocArgs);
        $streamed = (string) ob_get_clean();

        return $streamed.implode("\n", \WP_CLI::$log)."\n".(\WP_CLI::$success ?? '');
    }

    private function conversationId(): string
    {
        preg_match('/Conversation: ([0-9A-Z]{26})/', (string) \WP_CLI::$success, $m);

        return $m[1] ?? '';
    }

    public function test_a_run_prints_the_reply_and_the_summary_line(): void
    {
        $this->useEngine();

        $out = $this->send('hello');

        self::assertStringContainsString('reply to hello', $out);
        self::assertMatchesRegularExpression('/^Done\. Provider: scripted \| Model: scripted-model \| Tokens: 14 \| Iterations: 1 \| Conversation: [0-9A-Z]{26}$/', (string) \WP_CLI::$success);
    }

    public function test_a_run_saves_its_conversation_for_continue(): void
    {
        $this->useEngine();

        $this->send('hello');

        self::assertSame($this->conversationId(), $this->options['phpclaw_cli_last_conversation']);
    }

    public function test_continue_resumes_the_last_conversation(): void
    {
        $this->useEngine();
        $this->send('hello');
        $first = $this->conversationId();

        $out = $this->send('and then?', ['continue' => true]);

        self::assertSame($first, $this->conversationId());
        self::assertStringNotContainsString('No previous conversation', $out);
    }

    public function test_continue_without_a_saved_conversation_starts_a_new_one(): void
    {
        $this->useEngine();

        $out = $this->send('hello', ['continue' => true]);

        self::assertStringContainsString('No previous conversation to continue; starting a new one.', $out);
        self::assertNull(\WP_CLI::$error);
    }

    public function test_conv_id_continues_that_conversation_and_wins_over_continue(): void
    {
        $this->useEngine();
        $this->send('first');
        $first = $this->conversationId();
        $this->send('other');

        $this->send('back to the first', ['conv-id' => $first, 'continue' => true]);

        self::assertSame($first, $this->conversationId());
    }

    public function test_an_unknown_conv_id_says_a_new_conversation_was_started(): void
    {
        $this->useEngine();

        $out = $this->send('hello', ['conv-id' => '01WPNOSUCHCONVERSATION00AA']);

        self::assertStringContainsString('No stored conversation 01WPNOSUCHCONVERSATION00AA; started '.$this->conversationId().'.', $out);
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
                if ($key === '01WPOTHERUSERCONVERSATION0A') {
                    throw new ConversationAccessDeniedException('Access denied.');
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
        $this->useEngine(memory: $memory);

        $out = $this->send('hello', ['conv-id' => '01WPOTHERUSERCONVERSATION0A']);

        self::assertStringContainsString('Conversation 01WPOTHERUSERCONVERSATION0A is not available to the console; started '.$this->conversationId().'.', $out);
        self::assertNull(\WP_CLI::$error);
    }

    public function test_trace_prints_one_line_per_tool_call_and_never_the_result(): void
    {
        $this->useEngine(new ScriptedProvider(static function (string $message): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => 'ls '.$message]]);
            HookRegistry::fire('tool.after', ['tool_name' => 'shell_exec', 'tool_result' => 'SECRET RESULT BODY']);
        }));

        $out = $this->send('uploads', ['trace' => true]);

        self::assertStringContainsString('  > shell_exec: ls uploads', $out);
        self::assertStringNotContainsString('SECRET RESULT BODY', $out);
    }

    public function test_without_trace_no_tool_line_is_printed(): void
    {
        $this->useEngine(new ScriptedProvider(static function (): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'wp_posts', 'tool_input' => []]);
        }));

        $out = $this->send('posts');

        self::assertStringNotContainsString('> wp_posts', $out);
    }

    public function test_trace_shows_the_path_and_size_of_a_file_write_and_cuts_a_long_command(): void
    {
        $this->useEngine(new ScriptedProvider(static function (): void {
            HookRegistry::fire('tool.before', ['tool_name' => 'file_write', 'tool_input' => ['path' => 'notes.txt', 'content' => 'private body']]);
            HookRegistry::fire('tool.before', ['tool_name' => 'file_read', 'tool_input' => ['file' => 'readme.html']]);
            HookRegistry::fire('tool.before', ['tool_name' => 'shell_exec', 'tool_input' => ['command' => str_repeat('a', 100)."\nrm -rf"]]);
        }));

        $out = $this->send('files', ['trace' => true]);

        self::assertStringContainsString('> file_write: notes.txt (12 bytes)', $out);
        self::assertStringContainsString('> file_read: readme.html', $out);
        self::assertStringContainsString('> shell_exec: '.str_repeat('a', 80).'...', $out);
        self::assertStringNotContainsString('private body', $out);
        self::assertStringNotContainsString('rm -rf', $out);
    }

    public function test_the_trace_listener_is_removed_even_when_the_run_fails(): void
    {
        $this->useEngine(new ScriptedProvider(static function (): void {
            throw new GuardException('Prompt injection detected');
        }));
        $before = HookRegistry::count('tool.before');

        $this->send('hello', ['trace' => true]);

        self::assertStringContainsString('blocked by a security guard', (string) \WP_CLI::$error);
        self::assertSame($before, HookRegistry::count('tool.before'));
    }

    public function test_a_stream_run_sends_the_message_and_prints_the_summary_line(): void
    {
        $provider = new ScriptedProvider;
        $this->useEngine($provider);

        $this->send('hello', ['stream' => true]);

        self::assertSame(['hello'], $provider->received);
        self::assertStringStartsWith('Done. Provider: scripted | Model: scripted-model | Tokens: 14 | Iterations: 1', (string) \WP_CLI::$success);
        self::assertSame($this->conversationId(), $this->options['phpclaw_cli_last_conversation']);
    }

    public function test_an_empty_streamed_reply_prints_the_empty_response_text(): void
    {
        $provider = new class implements ProviderInterface
        {
            public function send(array $messages, array $tools = []): array
            {
                return ['type' => 'text', 'text' => ''];
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }

            public function name(): string
            {
                return 'scripted';
            }

            public function model(): string
            {
                return 'scripted-model';
            }
        };
        $this->useEngine();
        ProviderRegistry::register('scripted', static fn () => $provider);
        $this->useEngineAgain();

        $out = $this->send('hello', ['stream' => true]);

        self::assertStringContainsString(PhpClawRestController::EMPTY_RESPONSE_TEXT, $out);
    }

    private function useEngineAgain(): void
    {
        $engine = EngineFactory::build(config: [], saved: $this->options['phpclaw_settings']);
        $plugin = \Mockery::mock(Plugin::class);
        $plugin->allows('engine')->andReturn($engine);
        $plugin->allows('config')->andReturn([]);
        (new \ReflectionClass(Plugin::class))->getProperty('instance')->setValue(null, $plugin);
    }
}
