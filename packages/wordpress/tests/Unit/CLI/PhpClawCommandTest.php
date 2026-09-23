<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\CLI;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\WordPress\CLI\PhpClawCommand;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpClawCommand::class)]
final class PhpClawCommandTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (! class_exists('WP_CLI')) {
            eval('
            final class WP_CLI {
                public static array $log = [];
                public static ?string $error = null;
                public static ?string $success = null;

                public static function log(string $msg): void { self::$log[] = $msg; }
                public static function error(string $msg, bool $exit = true): void { self::$error = $msg; }
                public static function success(string $msg): void { self::$success = $msg; }
                public static function add_command(string $name, mixed $cmd, array $args = []): void {}
            }
            ');
        }

        \WP_CLI::$log = [];
        \WP_CLI::$error = null;
        \WP_CLI::$success = null;
    }

    protected function tearDown(): void
    {
        (new \ReflectionClass(Plugin::class))
            ->getProperty('instance')
            ->setValue(null, null);

        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_it_calls_engine_send_and_outputs_response(): void
    {
        $response = new AgentResponse(
            text: 'Paris is the capital of France.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            inputTokens: 20,
            outputTokens: 22,
        );

        $conv = Conversation::start();
        $turn = new ConversationTurn(response: $response, conversation: $conv);
        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andReturn($turn);

        $plugin = \Mockery::mock(Plugin::class);
        $plugin->allows('engine')->andReturn($engine);
        $plugin->allows('config')->andReturn([
            'api_key' => '',
            'provider' => '',
            'model' => '',
            'store_messages' => false,
            'max_iterations' => 10,
            'shell_allowlist' => [],
        ]);

        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, $plugin);

        $command = new PhpClawCommand;
        $command(['What is the capital of France?'], []);

        self::assertSame('Paris is the capital of France.', \WP_CLI::$log[1] ?? '');
        self::assertStringContainsString('anthropic', \WP_CLI::$success ?? '');
    }

    public function test_it_errors_on_empty_message(): void
    {
        $command = new PhpClawCommand;
        $command([''], []);

        self::assertStringContainsString('empty', \WP_CLI::$error ?? '');
    }

    private function injectPlugin(PhpClawInterface $engine, array $config = []): void
    {
        $defaults = [
            'api_key' => '',
            'provider' => '',
            'model' => '',
            'store_messages' => false,
            'max_iterations' => 10,
            'shell_allowlist' => [],
        ];

        $plugin = \Mockery::mock(Plugin::class);
        $plugin->allows('engine')->andReturn($engine);
        $plugin->allows('config')->andReturn(array_merge($defaults, $config));

        $ref = new \ReflectionClass(Plugin::class);
        $prop = $ref->getProperty('instance');
        $prop->setValue(null, $plugin);
    }

    public function test_run_sync_guard_exception_emits_neutral_guard_message(): void
    {
        $conv = Conversation::start();
        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andThrow(new GuardException('Rate limit exceeded: maximum 2 requests per 60 seconds.'));

        $this->injectPlugin($engine);

        (new PhpClawCommand)(['hello'], []);

        self::assertStringContainsString('blocked by a security guard', \WP_CLI::$error ?? '');
        self::assertStringNotContainsString('prompt injection', \WP_CLI::$error ?? '');
    }

    public function test_run_sync_generic_throwable_emits_generic_error(): void
    {
        $conv = Conversation::start();
        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andThrow(new \RuntimeException('boom'));

        $this->injectPlugin($engine);

        (new PhpClawCommand)(['hello'], []);

        self::assertNotNull(\WP_CLI::$error);
        self::assertStringContainsString('error occurred', \WP_CLI::$error);
        self::assertStringNotContainsString('boom', \WP_CLI::$error);
    }

    public function test_stream_mode_invokes_engine_stream_and_completes(): void
    {
        $response = new AgentResponse(
            text: 'chunk one chunk two',
            provider: 'ollama',
            model: 'qwen2.5:7b',
            iterations: 1,
        );

        $conv = Conversation::start();
        $turn = new ConversationTurn(response: $response, conversation: $conv);
        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andReturnUsing(static function ($c, $message, callable $cb) use ($turn): ConversationTurn {
            $cb('chunk one ');
            $cb('chunk two');

            return $turn;
        });

        $this->injectPlugin($engine);

        (new PhpClawCommand)(['stream me'], ['stream' => true]);

        self::assertStringContainsString('Streaming complete', \WP_CLI::$success ?? '');
        self::assertNull(\WP_CLI::$error);
    }

    public function test_stream_mode_guard_exception_emits_neutral_guard_message(): void
    {
        $conv = Conversation::start();
        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andThrow(new GuardException('Cloud security scan response could not be verified.'));

        $this->injectPlugin($engine);

        (new PhpClawCommand)(['stream me'], ['stream' => true]);

        self::assertStringContainsString('blocked by a security guard', \WP_CLI::$error ?? '');
        self::assertStringNotContainsString('prompt injection', \WP_CLI::$error ?? '');
    }

    public function test_stream_mode_generic_throwable_emits_generic_error(): void
    {
        $conv = Conversation::start();
        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->expects('conversation')->once()->andReturn($conv);
        $engine->expects('streamInConversation')->once()->andThrow(new \RuntimeException('oh no'));

        $this->injectPlugin($engine);

        (new PhpClawCommand)(['stream me'], ['stream' => true]);

        self::assertStringContainsString('error occurred', \WP_CLI::$error ?? '');
        self::assertStringNotContainsString('oh no', \WP_CLI::$error ?? '');
    }

    public function test_provider_override_does_not_use_the_injected_engine(): void
    {

        $wpdb = \Mockery::mock();
        $wpdb->shouldIgnoreMissing();
        $wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $wpdb;

        $engine = \Mockery::mock(PhpClawInterface::class);
        $engine->shouldNotReceive('send');
        $this->injectPlugin($engine);

        Functions\when('get_option')->justReturn([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'api_key' => '',
            'store_messages' => '0',
            'max_iterations' => 5,
        ]);

        try {
            (new PhpClawCommand)(['hi'], ['provider' => 'ollama', 'model' => 'qwen2.5:7b']);
        } catch (\Throwable) {
        }

        unset($GLOBALS['wpdb']);

        $engine->mockery_verify();
    }
}
