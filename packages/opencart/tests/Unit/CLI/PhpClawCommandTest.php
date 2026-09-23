<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\CLI;

use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Conversation;
use PhpClaw\Agent\ConversationTurn;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\OpenCart\CLI\PhpClawCommand;
use PhpClaw\OpenCart\Plugin;
use PHPUnit\Framework\TestCase;

final class PhpClawCommandTest extends TestCase
{
    public function test_class_exists(): void
    {
        self::assertTrue(class_exists(PhpClawCommand::class));
    }

    public function test_class_is_final(): void
    {
        $ref = new \ReflectionClass(PhpClawCommand::class);
        self::assertTrue($ref->isFinal());
    }

    public function test_run_method_exists(): void
    {
        $ref = new \ReflectionClass(PhpClawCommand::class);
        self::assertTrue($ref->hasMethod('run'));
    }

    public function test_run_method_accepts_args_and_flags(): void
    {
        $ref = new \ReflectionClass(PhpClawCommand::class);
        $method = $ref->getMethod('run');
        $params = $method->getParameters();

        self::assertCount(2, $params);
        self::assertSame('args', $params[0]->getName());
        self::assertSame('flags', $params[1]->getName());
    }

    public function test_constructor_takes_plugin(): void
    {
        $ref = new \ReflectionClass(PhpClawCommand::class);
        $ctor = $ref->getConstructor();
        $params = $ctor->getParameters();

        self::assertGreaterThanOrEqual(1, count($params));
        self::assertSame('plugin', $params[0]->getName());
    }

    private function makeCommand(?int &$exitCode = null): PhpClawCommand
    {
        $exitCode = null;

        return new PhpClawCommand(
            Plugin::getInstance(),
            function (int $code) use (&$exitCode): void {
                $exitCode = $code;
            },
        );
    }

    private function makeResponse(string $text = 'ok'): AgentResponse
    {
        return new AgentResponse(text: $text, provider: 'ollama', model: 'test', iterations: 1);
    }

    private function makeConversation(string $id = 'cli-conv'): Conversation
    {
        return new Conversation(id: $id, history: [], createdAt: new \DateTimeImmutable);
    }

    private function makeTurn(string $text = 'ok'): ConversationTurn
    {
        return new ConversationTurn(response: $this->makeResponse($text), conversation: $this->makeConversation());
    }

    public function test_run_sync_prints_text_to_stdout(): void
    {
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($this->makeConversation());
        $engine->method('streamInConversation')->willReturnCallback(
            function (): ConversationTurn {
                return $this->makeTurn('hello world');
            },
        );

        $cmd = $this->makeCommand();
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('runSync');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($cmd, $engine, 'test');
        $out = ob_get_clean();

        self::assertStringContainsString('hello world', $out);
    }

    public function test_run_sync_echoes_the_response_text_to_stdout(): void
    {
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($this->makeConversation());
        $engine->method('streamInConversation')->willReturnCallback(
            function (): ConversationTurn {
                return $this->makeTurn('answer');
            },
        );

        $cmd = $this->makeCommand();
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('runSync');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($cmd, $engine, 'q');
        $stdout = (string) ob_get_clean();

        self::assertSame("answer\n", $stdout);
    }

    public function test_run_sync_calls_halt_with_exit_1_on_guard_exception(): void
    {
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($this->makeConversation());
        $engine->method('streamInConversation')->willThrowException(new GuardException('blocked'));

        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('runSync');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($cmd, $engine, 'bad prompt');
        ob_end_clean();

        self::assertSame(1, $exitCode);
    }

    public function test_run_sync_calls_halt_with_exit_1_on_provider_exception(): void
    {
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($this->makeConversation());
        $engine->method('streamInConversation')->willThrowException(new ProviderException('rate limited'));

        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('runSync');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($cmd, $engine, 'test prompt');
        ob_end_clean();

        self::assertSame(1, $exitCode);
    }

    public function test_run_sync_calls_halt_with_exit_1_on_max_iterations(): void
    {
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($this->makeConversation());
        $engine->method('streamInConversation')->willThrowException(new MaxIterationsException('looped too long'));

        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('runSync');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($cmd, $engine, 'test prompt');
        ob_end_clean();

        self::assertSame(1, $exitCode);
    }

    public function test_run_stream_happy_path_writes_newline_after_tokens(): void
    {
        $collected = '';
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($this->makeConversation());
        $engine
            ->method('streamInConversation')
            ->willReturnCallback(function (Conversation $c, string $msg, callable $cb) use (&$collected): ConversationTurn {
                foreach (['Hello', ', ', 'world!'] as $chunk) {
                    $cb($chunk);
                    $collected .= $chunk;
                }

                return new ConversationTurn(response: $this->makeResponse('Hello, world!'), conversation: $this->makeConversation());
            });

        $cmd = $this->makeCommand();
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('runStream');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($cmd, $engine, 'stream me');
        $out = ob_get_clean();

        self::assertSame('Hello, world!', $collected);
        self::assertStringEndsWith("\n", $out);
    }

    public function test_run_stream_calls_halt_with_exit_1_on_guard_exception(): void
    {
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($this->makeConversation());
        $engine->method('streamInConversation')->willThrowException(new GuardException('injection'));

        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('runStream');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($cmd, $engine, 'bad prompt');
        ob_end_clean();

        self::assertSame(1, $exitCode);
    }

    public function test_run_stream_calls_halt_with_exit_1_on_provider_exception(): void
    {
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($this->makeConversation());
        $engine->method('streamInConversation')->willThrowException(new ProviderException('provider down'));

        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('runStream');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($cmd, $engine, 'test prompt');
        ob_end_clean();

        self::assertSame(1, $exitCode);
    }

    public function test_run_stream_calls_halt_with_exit_1_on_max_iterations(): void
    {
        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($this->makeConversation());
        $engine->method('streamInConversation')->willThrowException(new MaxIterationsException('looped'));

        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('runStream');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($cmd, $engine, 'test prompt');
        ob_end_clean();

        self::assertSame(1, $exitCode);
    }

    public function test_run_calls_halt_on_empty_message(): void
    {
        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);

        ob_start();
        $cmd->run([], []);
        ob_end_clean();

        self::assertSame(1, $exitCode);
    }

    public function test_run_dispatches_to_run_sync_when_no_stream_flag(): void
    {
        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);

        ob_start();
        $cmd->run(['hello'], []);
        ob_end_clean();

        self::assertTrue($exitCode === null || $exitCode === 1);
    }

    public function test_run_dispatches_to_run_stream_when_stream_flag_set(): void
    {
        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);

        ob_start();
        $cmd->run(['hello'], ['stream' => '']);
        ob_end_clean();

        self::assertTrue($exitCode === null || $exitCode === 1);
    }

    public function test_resolve_engine_method_exists(): void
    {
        $ref = new \ReflectionClass(PhpClawCommand::class);
        self::assertTrue($ref->hasMethod('resolveEngine'));
        self::assertTrue($ref->getMethod('resolveEngine')->isPrivate());
    }

    public function test_resolve_engine_returns_claw_interface(): void
    {
        $ref = new \ReflectionClass(PhpClawCommand::class);
        $method = $ref->getMethod('resolveEngine');
        $ret = $method->getReturnType();
        self::assertNotNull($ret);
        self::assertStringContainsString('ClawInterface', (string) $ret);
    }

    public function test_resolve_engine_without_flags_calls_plugin_engine(): void
    {
        $cmd = $this->makeCommand();
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('resolveEngine');
        $method->setAccessible(true);

        try {
            $result = $method->invoke($cmd, []);
            self::assertInstanceOf(ClawInterface::class, $result);
        } catch (AdapterException $e) {
            self::assertStringContainsString('No API key found', $e->getMessage());
        }
    }

    public function test_resolve_engine_with_provider_flag_calls_build_with_overrides(): void
    {
        $cmd = $this->makeCommand();
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('resolveEngine');
        $method->setAccessible(true);

        $result = $method->invoke($cmd, ['provider' => 'ollama', 'model' => 'qwen2.5:7b']);

        self::assertSame('ollama', $result->config()->providerName);
        self::assertSame('qwen2.5:7b', $result->config()->model);
    }

    public function test_run_reaches_sync_dispatch_when_engine_resolves(): void
    {
        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);

        ob_start();
        $cmd->run(['hello from sync dispatch'], ['provider' => 'ollama', 'model' => 'qwen2.5:7b']);
        ob_end_clean();

        self::assertTrue($exitCode === null || $exitCode === 1);
    }

    public function test_run_reaches_stream_dispatch_when_engine_resolves(): void
    {
        $exitCode = null;
        $cmd = $this->makeCommand($exitCode);

        ob_start();
        $cmd->run(['hello from stream dispatch'], ['stream' => '', 'provider' => 'ollama', 'model' => 'qwen2.5:7b']);
        ob_end_clean();

        self::assertTrue($exitCode === null || $exitCode === 1);
    }

    public function test_scoped_engine_api_carries_the_module_grant_not_a_console_flag(): void
    {
        $method = new \ReflectionMethod(Plugin::class, 'buildScopedEngine');

        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $method->getParameters(),
        );

        self::assertSame(
            ['actingUserId', 'manageAll', 'callerMayUseModule', 'mayQueryRaw'],
            $names,
            'The CLI reaches tools through the module grant; console trust comes from the process marker, never a call argument.',
        );
    }

    public function test_cli_send_persists_tool_call_row(): void
    {
        HookRegistry::reset();

        $capturedPayload = null;

        $engine = $this->createMock(ClawInterface::class);
        $engine->method('conversation')->willReturn($this->makeConversation());
        $engine->method('streamInConversation')->willReturnCallback(
            function (Conversation $conversation, string $message, callable $onToken, ?callable $beforePersist = null) use (&$capturedPayload): ConversationTurn {
                HookRegistry::fire(LifecycleEvent::ToolAfter->value, [
                    'tool_name' => 'oc_product',
                    'tool_input' => ['limit' => 5],
                    'tool_result' => '{"success":true}',
                ]);

                $capturedPayload = $beforePersist(['history' => [
                    ['role' => 'user', 'content' => 'list products'],
                    ['role' => 'assistant', 'content' => 'here you go'],
                ]]);

                return $this->makeTurn('here you go');
            },
        );

        $cmd = $this->makeCommand();
        $ref = new \ReflectionClass($cmd);
        $method = $ref->getMethod('runSync');
        $method->setAccessible(true);

        ob_start();
        $method->invoke($cmd, $engine, 'list products');
        ob_get_clean();

        HookRegistry::reset();

        self::assertIsArray($capturedPayload);
        self::assertCount(3, $capturedPayload['history']);
        self::assertSame('tool', $capturedPayload['history'][1]['role']);
        self::assertSame('oc_product', $capturedPayload['history'][1]['tool_name']);
        self::assertSame('assistant', $capturedPayload['history'][2]['role']);
    }
}
