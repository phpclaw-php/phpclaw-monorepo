<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Agent;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\MaxIterationsException;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    private function makeProvider(array $responses): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');

        $call = 0;
        $mock->method('send')
            ->willReturnCallback(function () use ($responses, &$call): array {
                return $responses[$call++] ?? ['type' => 'text', 'text' => 'fallback'];
            });

        return $mock;
    }

    private function makeStreamProvider(string $fullText): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('stream')
            ->willReturnCallback(function (array $messages, callable $onToken) use ($fullText): string {
                foreach (str_split($fullText) as $char) {
                    $onToken($char);
                }

                return $fullText;
            });

        return $mock;
    }

    private function makeTool(string $name, string $result = 'tool_output'): ToolInterface
    {
        return new class($name, $result) implements ToolInterface
        {
            public function __construct(
                private readonly string $n,
                private readonly string $r,
            ) {}

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return 'A test tool';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $input): string
            {
                return $this->r;
            }
        };
    }

    private function makeFailingTool(string $name): ToolInterface
    {
        return new class($name) implements ToolInterface
        {
            public function __construct(private readonly string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return 'Fails';
            }

            public function inputSchema(): array
            {
                return [];
            }

            public function execute(array $input): string
            {
                throw new ToolException("Tool '{$this->n}' failed.");
            }
        };
    }

    public function test_run_returns_agent_response_on_text(): void
    {
        $provider = $this->makeProvider([
            ['type' => 'text', 'text' => 'Hello!', 'input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $agent = new Agent($provider, new ToolRegistry);
        $response = $agent->run('Hi');

        $this->assertInstanceOf(AgentResponse::class, $response);
        $this->assertSame('Hello!', $response->text);
        $this->assertSame('anthropic', $response->provider);
        $this->assertSame('claude-haiku-4-5-20251001', $response->model);
        $this->assertSame(1, $response->iterations);
        $this->assertSame(10, $response->inputTokens);
        $this->assertSame(5, $response->outputTokens);
    }

    public function test_run_records_duration_ms(): void
    {
        $provider = $this->makeProvider([
            ['type' => 'text', 'text' => 'Hello!'],
        ]);

        $agent = new Agent($provider, new ToolRegistry);
        $response = $agent->run('Hi');

        $this->assertGreaterThanOrEqual(0, $response->durationMs);
        $this->assertIsInt($response->durationMs);
    }

    public function test_run_tools_called_is_empty_for_direct_text_response(): void
    {
        $provider = $this->makeProvider([
            ['type' => 'text', 'text' => 'Hello!'],
        ]);

        $agent = new Agent($provider, new ToolRegistry);
        $response = $agent->run('Hi');

        $this->assertSame([], $response->toolsCalled);
        $this->assertFalse($response->usedTools());
    }

    public function test_run_tools_called_populated_when_tools_used(): void
    {
        $provider = $this->makeProvider([
            [
                'type' => 'tool_use_batch',
                'calls' => [
                    ['tool_use_id' => 'toolu_01', 'tool_name' => 'my_tool', 'tool_input' => []],
                ],
            ],
            [
                'type' => 'tool_use_batch',
                'calls' => [
                    ['tool_use_id' => 'toolu_02', 'tool_name' => 'my_tool', 'tool_input' => []],
                ],
            ],
            ['type' => 'text', 'text' => 'Done.'],
        ]);

        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('my_tool', 'output')]);

        $agent = new Agent($provider, $registry);
        $response = $agent->run('Use tool twice');

        $this->assertSame(['my_tool', 'my_tool'], $response->toolsCalled);
        $this->assertTrue($response->usedTools());
        $this->assertSame(['my_tool'], $response->uniqueToolsCalled());
    }

    public function test_run_appends_user_message_before_sending(): void
    {
        $capturedMessages = null;

        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): array {
                $capturedMessages = $messages;

                return ['type' => 'text', 'text' => 'ok'];
            });

        $agent = new Agent($mock, new ToolRegistry);
        $agent->run('What is 2+2?');

        $this->assertNotNull($capturedMessages);
        $this->assertCount(1, $capturedMessages);
        $this->assertSame('user', $capturedMessages[0]->role);
        $this->assertSame('What is 2+2?', $capturedMessages[0]->content);
    }

    public function test_run_executes_tool_and_returns_text_on_second_iteration(): void
    {
        $provider = $this->makeProvider([
            [
                'type' => 'tool_use_batch',
                'calls' => [
                    ['tool_use_id' => 'toolu_01', 'tool_name' => 'my_tool', 'tool_input' => ['key' => 'value']],
                ],
            ],
            ['type' => 'text', 'text' => 'Done after tool.'],
        ]);

        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('my_tool', 'tool output here')]);

        $agent = new Agent($provider, $registry);
        $response = $agent->run('Do something');

        $this->assertSame('Done after tool.', $response->text);
        $this->assertSame(2, $response->iterations);
    }

    public function test_run_sends_tool_result_in_history(): void
    {
        $allCallMessages = [];

        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');

        $call = 0;
        $mock->method('send')
            ->willReturnCallback(function (array $messages) use (&$allCallMessages, &$call): array {
                $allCallMessages[$call] = $messages;
                $call++;

                if ($call === 1) {
                    return [
                        'type' => 'tool_use_batch',
                        'calls' => [
                            ['tool_use_id' => 'id_123', 'tool_name' => 'echo_tool', 'tool_input' => ['msg' => 'hi']],
                        ],
                    ];
                }

                return ['type' => 'text', 'text' => 'final'];
            });

        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('echo_tool', 'echoed result')]);

        $agent = new Agent($mock, $registry);
        $agent->run('Call echo');

        $secondCallMessages = $allCallMessages[1];
        $this->assertCount(2, $secondCallMessages);

        $this->assertSame('user', $secondCallMessages[0]->role);

        $this->assertTrue($secondCallMessages[1]->isBatchToolUse());
        $this->assertSame('id_123', $secondCallMessages[1]->batchCalls[0]['tool_use_id']);
        $this->assertSame('echo_tool', $secondCallMessages[1]->batchCalls[0]['tool_name']);
        $this->assertSame('echoed result', $secondCallMessages[1]->batchResults['id_123']);
    }

    public function test_run_handles_unregistered_tool_gracefully(): void
    {
        $provider = $this->makeProvider([
            [
                'type' => 'tool_use_batch',
                'calls' => [
                    ['tool_use_id' => 'toolu_x', 'tool_name' => 'nonexistent', 'tool_input' => []],
                ],
            ],
            ['type' => 'text', 'text' => 'Handled error.'],
        ]);

        $agent = new Agent($provider, new ToolRegistry);
        $response = $agent->run('Use unknown tool');

        $this->assertSame('Handled error.', $response->text);
    }

    public function test_run_handles_tool_exception_gracefully(): void
    {
        $provider = $this->makeProvider([
            [
                'type' => 'tool_use_batch',
                'calls' => [
                    ['tool_use_id' => 'toolu_fail', 'tool_name' => 'bad_tool', 'tool_input' => []],
                ],
            ],
            ['type' => 'text', 'text' => 'Recovered from error.'],
        ]);

        $registry = new ToolRegistry;
        $registry->register([$this->makeFailingTool('bad_tool')]);

        $agent = new Agent($provider, $registry);
        $response = $agent->run('Use bad tool');

        $this->assertSame('Recovered from error.', $response->text);
    }

    public function test_run_throws_max_iterations_exception(): void
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturn([
            'type' => 'tool_use_batch',
            'calls' => [
                ['tool_use_id' => 'toolu_inf', 'tool_name' => 'infinite_tool', 'tool_input' => []],
            ],
        ]);

        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('infinite_tool')]);

        $agent = new Agent($mock, $registry, maxIterations: 3);

        $this->expectException(MaxIterationsException::class);
        $agent->run('Go forever');
    }

    public function test_stream_returns_agent_response_with_full_text(): void
    {
        $provider = $this->makeStreamProvider('Hello world');
        $agent = new Agent($provider, new ToolRegistry);

        $tokens = [];
        $response = $agent->stream('say hello', function (string $t) use (&$tokens): void {
            $tokens[] = $t;
        });

        $this->assertInstanceOf(AgentResponse::class, $response);
        $this->assertSame('Hello world', $response->text);
        $this->assertSame(1, $response->iterations);
        $this->assertGreaterThanOrEqual(0, $response->durationMs);
        $this->assertSame([], $response->toolsCalled);
        $this->assertNotEmpty($tokens);
    }

    public function test_stream_hybrid_emits_text_via_on_token_when_no_tool_called(): void
    {
        $provider = $this->makeProvider([
            ['type' => 'text', 'text' => 'Direct answer.'],
        ]);

        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('some_tool')]);

        $agent = new Agent($provider, $registry);
        $received = '';

        $response = $agent->stream('ask something', function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame('Direct answer.', $response->text);
        $this->assertSame('Direct answer.', $received);
        $this->assertSame(1, $response->iterations);
        $this->assertSame([], $response->toolsCalled);
    }

    public function test_stream_hybrid_executes_tool_then_emits_final_text(): void
    {
        $provider = $this->makeProvider([
            [
                'type' => 'tool_use_batch',
                'calls' => [
                    ['tool_use_id' => 'toolu_stream_01', 'tool_name' => 'my_tool', 'tool_input' => []],
                ],
            ],
            ['type' => 'text', 'text' => 'Tool result processed.'],
        ]);

        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('my_tool', 'tool output')]);

        $agent = new Agent($provider, $registry);
        $received = '';

        $response = $agent->stream('do something', function (string $chunk) use (&$received): void {
            $received .= $chunk;
        });

        $this->assertSame('Tool result processed.', $response->text);
        $this->assertSame('Tool result processed.', $received);
        $this->assertSame(2, $response->iterations);
        $this->assertSame(['my_tool'], $response->toolsCalled);
        $this->assertTrue($response->usedTools());
    }

    public function test_stream_hybrid_populates_tools_called_with_duplicates(): void
    {
        $provider = $this->makeProvider([
            ['type' => 'tool_use_batch', 'calls' => [['tool_use_id' => 't1', 'tool_name' => 'my_tool', 'tool_input' => []]]],
            ['type' => 'tool_use_batch', 'calls' => [['tool_use_id' => 't2', 'tool_name' => 'my_tool', 'tool_input' => []]]],
            ['type' => 'text', 'text' => 'Done.'],
        ]);

        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('my_tool')]);

        $agent = new Agent($provider, $registry);
        $response = $agent->stream('go', function (string $c): void {});

        $this->assertSame(['my_tool', 'my_tool'], $response->toolsCalled);
        $this->assertSame(['my_tool'], $response->uniqueToolsCalled());
    }

    public function test_stream_hybrid_throws_max_iterations_when_tools_never_finish(): void
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')->willReturn([
            'type' => 'tool_use_batch',
            'calls' => [['tool_use_id' => 'tx', 'tool_name' => 'my_tool', 'tool_input' => []]],
        ]);

        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('my_tool')]);

        $agent = new Agent($mock, $registry, maxIterations: 2);

        $this->expectException(MaxIterationsException::class);
        $agent->stream('loop forever', function (string $c): void {});
    }

    public function test_run_executes_all_tools_in_batch_and_counts_as_one_iteration(): void
    {
        $provider = $this->makeProvider([
            [
                'type' => 'tool_use_batch',
                'calls' => [
                    ['tool_use_id' => 'id_a', 'tool_name' => 'tool_a', 'tool_input' => []],
                    ['tool_use_id' => 'id_b', 'tool_name' => 'tool_b', 'tool_input' => []],
                ],
            ],
            ['type' => 'text', 'text' => 'Both tools ran.'],
        ]);

        $registry = new ToolRegistry;
        $registry->register([
            $this->makeTool('tool_a', 'result_a'),
            $this->makeTool('tool_b', 'result_b'),
        ]);

        $agent = new Agent($provider, $registry);
        $response = $agent->run('Run both tools');

        $this->assertSame('Both tools ran.', $response->text);
        $this->assertSame(2, $response->iterations);
        $this->assertSame(['tool_a', 'tool_b'], $response->toolsCalled);
        $this->assertTrue($response->usedTools());
    }

    public function test_run_batch_appends_single_message_with_all_results(): void
    {
        $allCallMessages = [];

        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');

        $call = 0;
        $mock->method('send')
            ->willReturnCallback(function (array $messages) use (&$allCallMessages, &$call): array {
                $allCallMessages[$call] = $messages;
                $call++;

                if ($call === 1) {
                    return [
                        'type' => 'tool_use_batch',
                        'calls' => [
                            ['tool_use_id' => 'id_a', 'tool_name' => 'tool_a', 'tool_input' => []],
                            ['tool_use_id' => 'id_b', 'tool_name' => 'tool_b', 'tool_input' => []],
                        ],
                    ];
                }

                return ['type' => 'text', 'text' => 'done'];
            });

        $registry = new ToolRegistry;
        $registry->register([
            $this->makeTool('tool_a', 'result_a'),
            $this->makeTool('tool_b', 'result_b'),
        ]);

        $agent = new Agent($mock, $registry);
        $agent->run('Run batch');

        $secondCallMessages = $allCallMessages[1];
        $this->assertCount(2, $secondCallMessages);
        $this->assertTrue($secondCallMessages[1]->isBatchToolUse());
        $this->assertCount(2, $secondCallMessages[1]->batchCalls);
        $this->assertSame('result_a', $secondCallMessages[1]->batchResults['id_a']);
        $this->assertSame('result_b', $secondCallMessages[1]->batchResults['id_b']);
    }

    public function test_run_batch_fail_soft_continues_with_error_in_result(): void
    {
        $provider = $this->makeProvider([
            [
                'type' => 'tool_use_batch',
                'calls' => [
                    ['tool_use_id' => 'id_ok',  'tool_name' => 'good_tool', 'tool_input' => []],
                    ['tool_use_id' => 'id_err', 'tool_name' => 'bad_tool',  'tool_input' => []],
                ],
            ],
            ['type' => 'text', 'text' => 'Handled partial failure.'],
        ]);

        $registry = new ToolRegistry;
        $registry->register([
            $this->makeTool('good_tool', 'good result'),
            $this->makeFailingTool('bad_tool'),
        ]);

        $agent = new Agent($provider, $registry);
        $response = $agent->run('Run both, one fails');

        $this->assertSame('Handled partial failure.', $response->text);
        $this->assertSame(['good_tool', 'bad_tool'], $response->toolsCalled);
    }

    public function test_run_batch_unique_tools_called_deduplicates(): void
    {
        $provider = $this->makeProvider([
            [
                'type' => 'tool_use_batch',
                'calls' => [
                    ['tool_use_id' => 'id_1', 'tool_name' => 'tool_a', 'tool_input' => []],
                    ['tool_use_id' => 'id_2', 'tool_name' => 'tool_a', 'tool_input' => []],
                ],
            ],
            ['type' => 'text', 'text' => 'Done.'],
        ]);

        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('tool_a', 'result')]);

        $agent = new Agent($provider, $registry);
        $response = $agent->run('Same tool twice in batch');

        $this->assertSame(['tool_a', 'tool_a'], $response->toolsCalled);
        $this->assertSame(['tool_a'], $response->uniqueToolsCalled());
    }

    public function test_run_prepends_existing_history(): void
    {
        $capturedMessages = null;

        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');
        $mock->method('send')
            ->willReturnCallback(function (array $messages) use (&$capturedMessages): array {
                $capturedMessages = $messages;

                return ['type' => 'text', 'text' => 'ok'];
            });

        $existing = [
            Message::user('previous turn'),
            Message::assistant('previous answer'),
        ];

        $agent = new Agent($mock, new ToolRegistry);
        $agent->run('new question', $existing);

        $this->assertCount(3, $capturedMessages);
        $this->assertSame('previous turn', $capturedMessages[0]->content);
        $this->assertSame('previous answer', $capturedMessages[1]->content);
        $this->assertSame('new question', $capturedMessages[2]->content);
    }

    private function makeCapturingProvider(array $responses, ?array &$captured): ProviderInterface
    {
        $mock = $this->createMock(ProviderInterface::class);
        $mock->method('name')->willReturn('anthropic');
        $mock->method('model')->willReturn('claude-haiku-4-5-20251001');

        $call = 0;
        $mock->method('send')->willReturnCallback(function (array $messages) use ($responses, &$call, &$captured): array {
            $captured = $messages;

            return $responses[$call++] ?? ['type' => 'text', 'text' => 'fallback'];
        });

        return $mock;
    }

    private function runOneToolRound(string $toolOutput, int $budget): string
    {
        $registry = new ToolRegistry;
        $registry->register([$this->makeTool('t', $toolOutput)]);

        $captured = null;
        $provider = $this->makeCapturingProvider([
            ['type' => 'tool_use_batch', 'calls' => [['tool_use_id' => 'a', 'tool_name' => 't', 'tool_input' => []]]],
            ['type' => 'text', 'text' => 'done'],
        ], $captured);

        (new Agent($provider, $registry, maxToolResultTokens: $budget))->run('go');

        $last = end($captured);

        return (string) ($last->batchResults['a'] ?? '');
    }

    public function test_tool_result_over_budget_is_cut_and_marked(): void
    {
        $result = $this->runOneToolRound(str_repeat('x', 4000), 100);

        $this->assertStringStartsWith(str_repeat('x', 400), $result);
        $this->assertStringContainsString('[phpClaw: tool output cut at 100 tokens', $result);
        $this->assertLessThan(4000, strlen($result));
    }

    public function test_tool_result_under_budget_is_returned_unchanged(): void
    {
        $this->assertSame('tiny', $this->runOneToolRound('tiny', 100));
    }

    public function test_zero_budget_disables_the_cut(): void
    {
        $this->assertSame(str_repeat('x', 4000), $this->runOneToolRound(str_repeat('x', 4000), 0));
    }
}
