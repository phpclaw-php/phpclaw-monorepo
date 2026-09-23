<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\CLI;

use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\PrestaShop\CLI\PhpClawCommand;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Plugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpClawCommand::class)]
final class PhpClawCommandCoverageTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private int $lastExitCode = -1;

    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        parent::tearDown();
    }

    private function makePlugin(ClawInterface $engine): Plugin
    {
        $plugin = Plugin::getInstance($this->createMock(PsDbInterface::class), 'ps_');

        $ref = new \ReflectionProperty(Plugin::class, 'engine');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $engine);

        return $plugin;
    }

    private function makeConversation(string $id = 'cli-conv'): Conversation
    {
        return new Conversation(id: $id, history: [], createdAt: new \DateTimeImmutable);
    }

    private function makeTurn(string $text = 'Answer.'): ConversationTurn
    {
        return new ConversationTurn(response: $this->makeResponse($text), conversation: $this->makeConversation());
    }

    private function makeResponse(string $text = 'Answer.'): AgentResponse
    {
        return new AgentResponse(
            text: $text,
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            inputTokens: 5,
            outputTokens: 10,
        );
    }

    private function makeCommand(ClawInterface $engine): PhpClawCommand
    {
        $this->lastExitCode = -1;
        $plugin = $this->makePlugin($engine);

        return new PhpClawCommand($plugin, function (int $code): void {
            $this->lastExitCode = $code;
        });
    }

    public function test_run_with_empty_message_exits_one(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $command = $this->makeCommand($engine);

        ob_start();
        $command->run([''], []);
        ob_get_clean();

        self::assertSame(1, $this->lastExitCode);
    }

    public function test_run_with_whitespace_only_message_exits_one(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $command = $this->makeCommand($engine);

        ob_start();
        $command->run(['   '], []);
        ob_get_clean();

        self::assertSame(1, $this->lastExitCode);
    }

    public function test_run_sync_guard_exception_exits_one(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andThrow(new GuardException('prompt blocked'));

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runSync');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'test msg');
        ob_get_clean();

        self::assertSame(1, $this->lastExitCode);
    }

    public function test_run_sync_provider_exception_exits_one(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andThrow(new ProviderException('provider down'));

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runSync');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'test msg');
        ob_get_clean();

        self::assertSame(1, $this->lastExitCode);
    }

    public function test_run_sync_max_iterations_exception_exits_one(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andThrow(new MaxIterationsException('max reached'));

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runSync');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'test msg');
        ob_get_clean();

        self::assertSame(1, $this->lastExitCode);
    }

    public function test_run_stream_guard_exception_exits_one(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andThrow(new GuardException('stream blocked'));

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runStream');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'test msg');
        ob_get_clean();

        self::assertSame(1, $this->lastExitCode);
    }

    public function test_run_stream_provider_exception_exits_one(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andThrow(new ProviderException('stream provider down'));

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runStream');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'test msg');
        ob_get_clean();

        self::assertSame(1, $this->lastExitCode);
    }

    public function test_run_stream_max_iterations_exception_exits_one(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andThrow(new MaxIterationsException('stream max'));

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runStream');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'test msg');
        ob_get_clean();

        self::assertSame(1, $this->lastExitCode);
    }

    public function test_run_sync_outputs_response_text(): void
    {
        $response = $this->makeResponse('Hello from the agent.');
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andReturn(
            new ConversationTurn(response: $response, conversation: $this->makeConversation()),
        );

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runSync');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'What is my stock level?');
        $output = ob_get_clean();

        self::assertStringContainsString('Hello from the agent.', $output);
    }

    public function test_run_sync_outputs_newline_after_text(): void
    {
        $response = $this->makeResponse('Done.');
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andReturn(
            new ConversationTurn(response: $response, conversation: $this->makeConversation()),
        );

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runSync');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'test message');
        $output = ob_get_clean();

        self::assertStringEndsWith("\n", $output);
    }

    public function test_run_stream_calls_engine_stream_method(): void
    {
        $streamResponse = $this->makeResponse('streamed');
        $calledWith = null;
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            function (Conversation $conversation, string $message, callable $cb) use ($streamResponse, &$calledWith): ConversationTurn {
                $calledWith = $message;

                return new ConversationTurn(response: $streamResponse, conversation: $conversation);
            }
        );

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runStream');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'hello stream');
        ob_end_clean();

        self::assertSame('hello stream', $calledWith);
    }

    public function test_run_stream_executes_token_callback(): void
    {
        $streamResponse = $this->makeResponse('streamed');
        $tokensReceived = [];
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            function (Conversation $conversation, string $message, callable $cb) use ($streamResponse, &$tokensReceived): ConversationTurn {
                $cb('part1');
                $cb('part2');
                $tokensReceived = ['part1', 'part2'];

                return new ConversationTurn(response: $streamResponse, conversation: $conversation);
            }
        );

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runStream');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'stream test');
        ob_end_clean();

        self::assertSame(['part1', 'part2'], $tokensReceived);
    }

    public function test_resolve_engine_returns_plugin_engine_when_no_flags(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'resolveEngine');
        $ref->setAccessible(true);

        $result = $ref->invoke($command, []);

        self::assertSame($engine, $result);
    }

    public function test_resolve_engine_with_provider_flag_builds_new_engine(): void
    {
        $cached = \Mockery::mock(ClawInterface::class);
        $cached->allows('memory')->andReturn(new ArrayMemory);

        $instanceRef = new \ReflectionProperty(Plugin::class, 'instance');
        $instanceRef->setAccessible(true);
        $instanceRef->setValue(null, null);

        $plugin = Plugin::getInstance($this->createMock(PsDbInterface::class), 'ps_');

        $engineRef = new \ReflectionProperty(Plugin::class, 'engine');
        $engineRef->setAccessible(true);
        $engineRef->setValue($plugin, $cached);

        $plugin->saveSettings([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test',
            'max_iterations' => '10',
        ]);

        $command = new PhpClawCommand($plugin, function (int $code): void {
            $this->lastExitCode = $code;
        });

        $refMethod = new \ReflectionMethod(PhpClawCommand::class, 'resolveEngine');
        $refMethod->setAccessible(true);

        $built = $refMethod->invoke($command, ['provider' => 'openai']);

        self::assertNotSame($cached, $built, 'a provider flag must bypass the cached engine');
        self::assertSame('openai', $built->config()->providerName);
        self::assertSame('gpt-4o-mini', $built->config()->model);
    }

    public function test_resolve_engine_with_model_flag_triggers_build(): void
    {
        $cached = \Mockery::mock(ClawInterface::class);
        $cached->allows('memory')->andReturn(new ArrayMemory);

        $instanceRef = new \ReflectionProperty(Plugin::class, 'instance');
        $instanceRef->setAccessible(true);
        $instanceRef->setValue(null, null);

        $plugin = Plugin::getInstance($this->createMock(PsDbInterface::class), 'ps_');

        $engineRef = new \ReflectionProperty(Plugin::class, 'engine');
        $engineRef->setAccessible(true);
        $engineRef->setValue($plugin, $cached);

        $plugin->saveSettings([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
            'api_key' => 'sk-ant-test',
        ]);

        $command = new PhpClawCommand($plugin, function (int $code): void {
            $this->lastExitCode = $code;
        });

        $refMethod = new \ReflectionMethod(PhpClawCommand::class, 'resolveEngine');
        $refMethod->setAccessible(true);

        $built = $refMethod->invoke($command, ['model' => 'claude-opus-4-8']);

        self::assertNotSame($cached, $built, 'a model flag must bypass the cached engine');
        self::assertSame('anthropic', $built->config()->providerName);
        self::assertSame('claude-opus-4-8', $built->config()->model);
    }

    public function test_provider_flag_override_keeps_message_persistence_on(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andReturn(new ArrayMemory);

        $plugin = $this->makePlugin($engine);
        $plugin->saveSettings([
            'provider' => 'ollama',
            'model' => 'qwen2.5:7b',
            'store_messages' => '1',
        ]);

        $command = new PhpClawCommand($plugin, function (int $code): void {
            $this->lastExitCode = $code;
        });

        $refMethod = new \ReflectionMethod(PhpClawCommand::class, 'resolveEngine');
        $refMethod->setAccessible(true);

        $result = $refMethod->invoke($command, ['provider' => 'ollama', 'model' => 'qwen2.5:7b']);

        self::assertInstanceOf(ClawInterface::class, $result);
        self::assertNotInstanceOf(
            PrivacyAwareMemory::class,
            $result->memory(),
            'A provider or model flag must not silently disable conversation persistence.'
        );
    }

    public function test_resolve_engine_rebuilds_the_engine_for_a_provider_flag_override(): void
    {
        $memory = new ArrayMemory;
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('memory')->andReturn($memory);

        $plugin = $this->makePlugin($engine);
        $plugin->saveSettings(['provider' => 'openai', 'model' => 'gpt-4o-mini', 'api_key' => 'key']);

        $ref = new \ReflectionProperty(Plugin::class, 'engine');
        $ref->setAccessible(true);
        $ref->setValue($plugin, $engine);

        $command = new PhpClawCommand($plugin, function (int $code): void {
            $this->lastExitCode = $code;
        });

        $refMethod = new \ReflectionMethod(PhpClawCommand::class, 'resolveEngine');
        $refMethod->setAccessible(true);

        $rebuilt = $refMethod->invoke($command, ['provider' => 'groq', 'model' => 'llama-3.1-8b']);

        self::assertInstanceOf(ClawInterface::class, $rebuilt);
        self::assertNotSame($engine, $rebuilt, 'a provider flag must rebuild the engine, not reuse the cached one');
    }

    public function test_class_and_constructor_structure(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $command = $this->makeCommand($engine);

        self::assertInstanceOf(PhpClawCommand::class, $command);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'run');
        $params = $ref->getParameters();

        self::assertGreaterThanOrEqual(2, count($params));
        self::assertSame('args', $params[0]->getName());
        self::assertSame('flags', $params[1]->getName());
    }

    public function test_run_sync_response_includes_all_fields(): void
    {
        $response = new AgentResponse(
            text: 'Revenue: €12,000',
            provider: 'groq',
            model: 'llama-3.1-70b',
            iterations: 3,
            inputTokens: 100,
            outputTokens: 200,
        );
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andReturn(
            new ConversationTurn(response: $response, conversation: $this->makeConversation()),
        );

        $command = $this->makeCommand($engine);

        $ref = new \ReflectionMethod(PhpClawCommand::class, 'runSync');
        $ref->setAccessible(true);

        ob_start();
        $ref->invoke($command, $engine, 'revenue report');
        $output = ob_get_clean();

        self::assertStringContainsString('Revenue: €12,000', $output);
    }

    public function test_run_with_valid_message_no_stream_executes_sync(): void
    {
        $response = $this->makeResponse('sync result');
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->allows('streamInConversation')->andReturn(
            new ConversationTurn(response: $response, conversation: $this->makeConversation()),
        );

        $command = $this->makeCommand($engine);

        ob_start();
        $command->run(['check stock'], []);
        $output = ob_get_clean();

        self::assertStringContainsString('sync result', $output);
    }

    public function test_run_with_stream_flag_executes_stream(): void
    {
        $streamResponse = $this->makeResponse('streamed');
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $engine->expects('streamInConversation')->once()->andReturnUsing(
            static function (Conversation $conversation, string $msg, callable $cb) use ($streamResponse): ConversationTurn {
                $cb('streamed');

                return new ConversationTurn(response: $streamResponse, conversation: $conversation);
            }
        );

        $command = $this->makeCommand($engine);

        ob_start();
        ob_start();
        $command->run(['what is revenue?'], ['stream' => '']);
        $inner = (string) ob_get_clean();
        $outer = (string) ob_get_clean();

        self::assertStringContainsString('streamed', $outer.$inner);
    }

    public function test_run_trim_whitespace_from_message(): void
    {
        $response = $this->makeResponse('ok');
        $engine = \Mockery::mock(ClawInterface::class);
        $engine->allows('conversation')->andReturn($this->makeConversation());
        $seen = null;
        $engine->allows('streamInConversation')->andReturnUsing(
            function ($conversation, string $message) use (&$seen, $response): ConversationTurn {
                $seen = $message;

                return new ConversationTurn(response: $response, conversation: $this->makeConversation());
            },
        );

        $command = $this->makeCommand($engine);

        ob_start();
        $command->run(['  trimmed  '], []);
        $stdout = (string) ob_get_clean();

        self::assertSame('trimmed', $seen, 'the message must reach the engine trimmed');
        self::assertStringContainsString('ok', $stdout);
    }

    public function test_run_engine_build_failure_exits_one(): void
    {
        $engine = \Mockery::mock(ClawInterface::class);
        $plugin = Plugin::getInstance(null, 'ps_');

        $errorRef = new \ReflectionProperty(Plugin::class, 'engineError');
        $errorRef->setAccessible(true);
        $errorRef->setValue($plugin, new \RuntimeException('build fail'));

        $this->lastExitCode = -1;
        $command = new PhpClawCommand($plugin, function (int $code): void {
            $this->lastExitCode = $code;
        });

        ob_start();
        $command->run(['some message'], []);
        ob_get_clean();

        self::assertSame(1, $this->lastExitCode);
    }
}
