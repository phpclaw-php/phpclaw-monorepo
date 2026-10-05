<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Flow;

use PhpClaw\Claw;
use PhpClaw\Config\LoopConfig;
use PhpClaw\Exceptions\TokenBudgetExceededException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Flow\Support\ScriptedHttpClient;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;

final class TokenBudgetFlowTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
        GuardRegistry::reset();
        SkillRegistry::reset();
    }

    private function makeGetOrderTool(): ToolInterface
    {
        return new class implements ToolInterface
        {
            public function name(): string
            {
                return 'get_order';
            }

            public function description(): string
            {
                return 'Look up an order by id.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['order_id' => ['type' => 'string']]];
            }

            public function execute(array $input): string
            {
                return (string) json_encode(['success' => true, 'order_id' => $input['order_id'] ?? '']);
            }
        };
    }

    private function toolCallResponse(string $callId, string $orderId, int $inputTokens, int $outputTokens): array
    {
        return [
            'choices' => [
                [
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [
                            [
                                'id' => $callId,
                                'type' => 'function',
                                'function' => [
                                    'name' => 'get_order',
                                    'arguments' => json_encode(['order_id' => $orderId]),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'usage' => ['prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens],
        ];
    }

    private function textResponse(string $text, int $inputTokens, int $outputTokens): array
    {
        return [
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $text]],
            ],
            'usage' => ['prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens],
        ];
    }

    private function buildClaw(ScriptedHttpClient $http, int $maxTokenBudget, bool $withTool): Claw
    {
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');

        $builder = Claw::builder()
            ->provider('openai')
            ->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->maxTokenBudget($maxTokenBudget);

        if ($withTool) {
            $builder->addTool($this->makeGetOrderTool());
        }

        return $builder->build();
    }

    public function test_stops_before_the_call_that_would_exceed_the_budget_and_fires_the_event(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->toolCallResponse('call_1', '100', 100, 20));
        $http->queuePostResponse($this->toolCallResponse('call_2', '100', 110, 20));

        $captured = null;
        HookRegistry::on(LifecycleEvent::BudgetExceeded->value, function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $claw = $this->buildClaw($http, maxTokenBudget: 200, withTool: true);

        try {
            $claw->send('Fetch order 100, then verify its status, then confirm delivery.');
            self::fail('Expected TokenBudgetExceededException.');
        } catch (TokenBudgetExceededException $e) {
            self::assertSame(250, $e->tokensSpent);
            self::assertSame(200, $e->budget);
        }

        self::assertSame(2, $http->postCallCount);
        self::assertNotNull($captured);
        self::assertSame(250, $captured['tokens_spent']);
        self::assertSame(200, $captured['budget']);
    }

    public function test_zero_budget_is_unlimited_and_the_run_completes(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->toolCallResponse('call_1', '100', 100, 20));
        $http->queuePostResponse($this->toolCallResponse('call_2', '100', 110, 20));
        $http->queuePostResponse($this->textResponse('Order 100 confirmed delivered.', 50, 10));

        $claw = $this->buildClaw($http, maxTokenBudget: 0, withTool: true);

        $response = $claw->send('Fetch order 100, then verify its status, then confirm delivery.');

        self::assertSame('Order 100 confirmed delivered.', $response->text);
        self::assertSame(3, $http->postCallCount);
    }

    public function test_budget_large_enough_completes_without_firing_the_event(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->toolCallResponse('call_1', '100', 100, 20));
        $http->queuePostResponse($this->toolCallResponse('call_2', '100', 110, 20));
        $http->queuePostResponse($this->textResponse('Order 100 confirmed delivered.', 50, 10));

        $captured = null;
        HookRegistry::on(LifecycleEvent::BudgetExceeded->value, function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $claw = $this->buildClaw($http, maxTokenBudget: 100_000, withTool: true);

        $response = $claw->send('Fetch order 100, then verify its status, then confirm delivery.');

        self::assertSame('Order 100 confirmed delivered.', $response->text);
        self::assertSame(3, $http->postCallCount);
        self::assertNull($captured);
    }

    public function test_projection_exactly_equal_to_the_budget_does_not_throw(): void
    {
        $message = 'Please summarize this ticket for the customer right now.';
        $estimate = intdiv(strlen($message), 4);

        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->textResponse('Summary ready.', 5, 5));

        $claw = $this->buildClaw($http, maxTokenBudget: $estimate, withTool: false);

        $response = $claw->send($message);

        self::assertSame('Summary ready.', $response->text);
        self::assertSame(1, $http->postCallCount);
    }

    public function test_projection_one_above_the_budget_throws_before_any_call(): void
    {
        $message = 'Please summarize this ticket for the customer right now.';
        $estimate = intdiv(strlen($message), 4);
        $budget = $estimate - 1;

        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->textResponse('Summary ready.', 5, 5));

        $claw = $this->buildClaw($http, maxTokenBudget: $budget, withTool: false);

        try {
            $claw->send($message);
            self::fail('Expected TokenBudgetExceededException.');
        } catch (TokenBudgetExceededException $e) {
            self::assertSame(0, $e->tokensSpent);
            self::assertSame($budget, $e->budget);
        }

        self::assertSame(0, $http->postCallCount);
    }

    public function test_stream_with_tools_registered_goes_through_the_loop_and_is_budget_checked(): void
    {
        $message = 'Please summarize this ticket for the customer right now.';
        $estimate = intdiv(strlen($message), 4);
        $budget = $estimate - 1;

        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->textResponse('Summary ready.', 5, 5));

        $claw = $this->buildClaw($http, maxTokenBudget: $budget, withTool: true);

        try {
            $claw->stream($message, static function (string $token): void {});
            self::fail('Expected TokenBudgetExceededException.');
        } catch (TokenBudgetExceededException $e) {
            self::assertSame(0, $e->tokensSpent);
            self::assertSame($budget, $e->budget);
        }

        self::assertSame(0, $http->postCallCount);
        self::assertSame(0, $http->streamCallCount);
    }

    public function test_stream_fast_path_with_no_tools_is_never_budget_checked(): void
    {
        $message = 'Please summarize this ticket for the customer right now.';

        $http = new ScriptedHttpClient;
        $http->queueStreamLines([
            'data: {"choices":[{"delta":{"content":"Hello "}}]}',
            'data: {"choices":[{"delta":{"content":"there."}}]}',
            'data: [DONE]',
        ]);

        $captured = null;
        HookRegistry::on(LifecycleEvent::BudgetExceeded->value, function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $claw = $this->buildClaw($http, maxTokenBudget: 1, withTool: false);

        $collected = '';
        $response = $claw->stream($message, function (string $token) use (&$collected): void {
            $collected .= $token;
        });

        self::assertSame('Hello there.', $collected);
        self::assertSame('Hello there.', $response->text);
        self::assertSame(1, $http->streamCallCount);
        self::assertSame(0, $http->postCallCount);
        self::assertNull($captured);
    }

    public function test_loop_config_clamps_negative_budget_and_parse_retries_to_zero(): void
    {
        $limits = new LoopConfig(maxTokenBudget: -5, maxParseRetries: -3);

        self::assertSame(0, $limits->maxTokenBudget);
        self::assertSame(0, $limits->maxParseRetries);
    }

    public function test_max_parse_retries_setter_reaches_the_config(): void
    {
        $provider = $this->createMock(ProviderInterface::class);

        $claw = Claw::builder()
            ->provider('openai')
            ->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->maxParseRetries(5)
            ->build();

        self::assertSame(5, $claw->config()->maxParseRetries);
    }
}
