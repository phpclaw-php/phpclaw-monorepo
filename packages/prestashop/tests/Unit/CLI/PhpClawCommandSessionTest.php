<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\CLI;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\PrestaShop\CLI\PhpClawCommand;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpClawCommand::class)]
final class PhpClawCommandSessionTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private int $lastExitCode = -1;

    protected function setUp(): void
    {
        parent::setUp();
        HookRegistry::reset();
        MemoryRegistry::reset();
        \Configuration::reset();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        MemoryRegistry::reset();
        \Configuration::reset();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        parent::tearDown();
    }

    private function makeConversation(string $id = 'test-conv-001'): Conversation
    {
        return new Conversation(id: $id, history: [], createdAt: new \DateTimeImmutable);
    }

    private function makeResponse(string $text = 'answer'): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: 'anthropic',
            model: 'claude-haiku',
            iterations: 1,
            inputTokens: 5,
            outputTokens: 10,
        );
    }

    private function makeCommand(ClawInterface $engine): PhpClawCommand
    {
        $this->lastExitCode = -1;
        $plugin = Plugin::getInstance($this->createMock(PsDbInterface::class), 'ps_');

        $ref = new \ReflectionProperty(Plugin::class, 'engine');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $engine);

        return new PhpClawCommand($plugin, function (int $code): void {
            $this->lastExitCode = $code;
        });
    }

    public function test_run_with_conv_id_passes_id_to_engine_conversation(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $conv = $this->makeConversation('given-conv-id-123');
        $engine->shouldReceive('conversation')->twice()->with('given-conv-id-123')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturn(
            new ConversationTurn(response: $this->makeResponse(), conversation: $conv),
        );

        $command = $this->makeCommand($engine);

        $stderr = $this->captureStderr(static function () use ($command): void {
            ob_start();
            $command->run(['ask something'], ['conv-id' => 'given-conv-id-123']);
            ob_get_clean();
        });

        self::assertSame(-1, $this->lastExitCode);
        self::assertStringContainsString('conversation:given-conv-id-123]', $stderr);
        self::assertStringNotContainsString('No stored conversation', $stderr);
    }

    public function test_run_continue_without_saved_id_starts_new_and_prints_notice(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $conv = $this->makeConversation('new-conv-abc');
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturn(
            new ConversationTurn(response: $this->makeResponse(), conversation: $conv),
        );

        $command = $this->makeCommand($engine);

        $stderr = $this->captureStderr(function () use ($command): void {
            ob_start();
            $command->run(['ask something'], ['continue' => 'true']);
            ob_get_clean();
        });

        self::assertStringContainsString('No previous conversation to continue; starting a new one.', $stderr);
    }

    public function test_run_continue_with_saved_id_uses_it(): void
    {
        \Configuration::updateValue('PHPCLAW_CLI_LAST_CONV', 'saved-conv-xyz');

        $engine = \Mockery::mock(ClawInterface::class);
        $conv = $this->makeConversation('saved-conv-xyz');
        $passedId = null;
        $engine->allows('conversation')->andReturnUsing(function (string $id) use ($conv, &$passedId): Conversation {
            $passedId = $id;

            return $conv;
        });
        $engine->allows('streamInConversation')->andReturn(
            new ConversationTurn(response: $this->makeResponse(), conversation: $conv),
        );

        $command = $this->makeCommand($engine);

        ob_start();
        $command->run(['ask something'], ['continue' => 'true']);
        ob_get_clean();

        self::assertSame('saved-conv-xyz', $passedId);
    }

    public function test_run_remembers_conversation_after_send(): void
    {
        $conv = $this->makeConversation('remembered-conv-001');
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturn(
            new ConversationTurn(response: $this->makeResponse(), conversation: $conv),
        );

        $command = $this->makeCommand($engine);

        ob_start();
        $command->run(['message here'], []);
        ob_get_clean();

        self::assertSame('remembered-conv-001', \Configuration::get('PHPCLAW_CLI_LAST_CONV'));
    }

    public function test_run_tool_log_flag_prints_tool_line_for_each_call(): void
    {
        $conv = $this->makeConversation('tool-log-conv');
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturnUsing(
            static function (Conversation $conversation, string $msg, callable $cb) use ($conv): ConversationTurn {
                HookRegistry::fire('tool.before', ['tool_name' => 'ps_product', 'tool_input' => []]);

                return new ConversationTurn(
                    response: new AgentResponse(text: 'done', provider: 'anthropic', model: 'claude', iterations: 1),
                    conversation: $conv,
                );
            },
        );

        $command = $this->makeCommand($engine);

        $stderr = $this->captureStderr(function () use ($command): void {
            ob_start();
            $command->run(['message'], ['trace' => 'true']);
            ob_get_clean();
        });

        self::assertStringContainsString('  > ps_product', $stderr);
    }

    public function test_run_no_tool_log_flag_suppresses_tool_lines(): void
    {
        $conv = $this->makeConversation('no-tool-log-conv');
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturnUsing(
            static function (Conversation $conversation, string $msg, callable $cb) use ($conv): ConversationTurn {
                HookRegistry::fire('tool.before', ['tool_name' => 'ps_product', 'tool_input' => []]);

                return new ConversationTurn(
                    response: new AgentResponse(text: 'done', provider: 'anthropic', model: 'claude', iterations: 1),
                    conversation: $conv,
                );
            },
        );

        $command = $this->makeCommand($engine);

        $stderr = $this->captureStderr(function () use ($command): void {
            ob_start();
            $command->run(['message'], []);
            ob_get_clean();
        });

        self::assertStringNotContainsString('  > ps_product', $stderr);
    }

    public function test_run_guard_failure_keeps_the_existing_prompt_blocked_line(): void
    {
        $conv = $this->makeConversation();
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andThrow(new GuardException('secret path /var/www/secret.txt'));

        $command = $this->makeCommand($engine);

        $stderr = $this->captureStderr(function () use ($command): void {
            ob_start();
            $command->run(['bad prompt'], []);
            ob_get_clean();
        });

        self::assertStringContainsString('phpClaw: prompt blocked: secret path /var/www/secret.txt', $stderr);
        self::assertSame(1, $this->lastExitCode);
    }

    public function test_run_engine_build_failure_keeps_the_existing_line(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $errorRef = new \ReflectionProperty(Plugin::class, 'engineError');
        $errorRef->setAccessible(true);
        $errorRef->setValue($plugin, new \RuntimeException('key at /var/secret.txt'));

        $command = new PhpClawCommand($plugin, function (int $code): void {
            $this->lastExitCode = $code;
        });

        $stderr = $this->captureStderr(function () use ($command): void {
            ob_start();
            $command->run(['message'], []);
            ob_get_clean();
        });

        self::assertStringContainsString('phpClaw: failed to build engine: key at /var/secret.txt', $stderr);
        self::assertStringContainsString('phpClaw:', $stderr);
        self::assertSame(1, $this->lastExitCode);
    }

    public function test_sync_summary_goes_to_stderr_with_expected_fields(): void
    {
        $memory = new ArrayMemory;
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $plugin = Plugin::getInstance($this->createMock(PsDbInterface::class), 'ps_');
        $plugin->saveSettings(['provider' => 'anthropic', 'model' => 'claude', 'api_key' => 'sk-test']);

        $ref = new \ReflectionProperty(Plugin::class, 'engine');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $engine);

        $conv = $this->makeConversation('summary-conv');
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturn(
            new ConversationTurn(
                response: new AgentResponse(text: 'ok', provider: 'anthropic', model: 'claude', iterations: 2, inputTokens: 8, outputTokens: 12),
                conversation: $conv,
            ),
        );

        $command = new PhpClawCommand($plugin, function (int $code): void {
            $this->lastExitCode = $code;
        });

        $stderr = $this->captureStderr(function () use ($command): void {
            ob_start();
            $command->run(['tell me something'], []);
            ob_get_clean();
        });

        self::assertMatchesRegularExpression('/\n\[provider:anthropic model:claude tokens:\d+ iterations:2 conversation:summary-conv\]\n/', $stderr);
    }

    public function test_stream_summary_includes_expected_fields(): void
    {
        $conv = $this->makeConversation('stream-summary-conv');
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($conv);
        $engine->allows('streamInConversation')->andReturn(
            new ConversationTurn(
                response: new AgentResponse(text: '', provider: 'openai', model: 'gpt-4o', iterations: 1, inputTokens: 6, outputTokens: 9),
                conversation: $conv,
            ),
        );

        $command = $this->makeCommand($engine);

        $stderr = $this->captureStderr(function () use ($command): void {
            ob_start();
            $command->run(['query'], ['stream' => 'true']);
            ob_get_clean();
        });

        self::assertStringContainsString('[conversation:stream-summary-conv]', $stderr);
    }

    private function captureStderr(callable $run): string
    {
        StderrCapture2::$buffer = '';
        stream_filter_register('phpclaw_stderr_capture2', StderrCapture2::class);
        $filter = stream_filter_append(STDERR, 'phpclaw_stderr_capture2', STREAM_FILTER_WRITE);

        try {
            $run();
        } finally {
            stream_filter_remove($filter);
        }

        return StderrCapture2::$buffer;
    }
}

final class StderrCapture2 extends \php_user_filter
{
    public static string $buffer = '';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$buffer .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}
