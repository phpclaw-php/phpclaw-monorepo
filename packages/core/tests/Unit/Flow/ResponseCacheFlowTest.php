<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Flow;

use PhpClaw\Claw;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Providers\Tools\WebSearch;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Flow\Support\ArrayCache;
use PhpClaw\Tests\Unit\Flow\Support\ScriptedHttpClient;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;

final class ResponseCacheFlowTest extends TestCase
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

    private function openAiTextResponse(string $text, int $inputTokens, int $outputTokens): array
    {
        return [
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $text]],
            ],
            'usage' => ['prompt_tokens' => $inputTokens, 'completion_tokens' => $outputTokens],
        ];
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

    private function goalSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_id' => ['type' => 'string'],
                'total' => ['type' => 'number'],
            ],
            'required' => ['order_id', 'total'],
            'additionalProperties' => false,
        ];
    }

    private function makeCountingOrderTool(): ToolInterface
    {
        return new class implements ToolInterface
        {
            public int $count = 0;

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
                $this->count++;

                return (string) json_encode(['success' => true, 'order_id' => $input['order_id'] ?? '']);
            }
        };
    }

    private function makeCountingPlainProvider(string $text, int $inputTokens, int $outputTokens): ProviderInterface
    {
        return new class($text, $inputTokens, $outputTokens) implements ProviderInterface
        {
            public int $sendCount = 0;

            public function __construct(
                private readonly string $text,
                private readonly int $inputTokens,
                private readonly int $outputTokens,
            ) {}

            public function send(array $messages, array $tools = []): array
            {
                $this->sendCount++;

                return [
                    'type' => 'text',
                    'text' => $this->text,
                    'input_tokens' => $this->inputTokens,
                    'output_tokens' => $this->outputTokens,
                ];
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }

            public function name(): string
            {
                return 'plain';
            }

            public function model(): string
            {
                return 'plain-model';
            }
        };
    }

    public function test_a_provider_that_does_not_support_web_search_still_shares_a_cache_entry_across_two_claw_instances(): void
    {
        $cache = new ArrayCache;

        $providerA = $this->makeCountingPlainProvider('Fixed answer.', 12, 6);
        $clawA = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($providerA)
            ->responseCache($cache)
            ->build();
        $first = $clawA->send('Summarise this ticket.');

        $providerB = $this->makeCountingPlainProvider('Different answer if actually called.', 12, 6);
        $clawB = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($providerB)
            ->withProviderTool(new WebSearch)
            ->withProviderTool(new WebSearch)
            ->responseCache($cache)
            ->build();
        $second = $clawB->send('Summarise this ticket.');

        self::assertSame(1, $providerA->sendCount);
        self::assertSame(0, $providerB->sendCount, 'When no provider in the chain supports web search, a configured provider tool must not change the fingerprint.');
        self::assertSame('Fixed answer.', $first->text);
        self::assertSame('Fixed answer.', $second->text);
    }

    public function test_custom_provider_tools_on_a_web_search_capable_provider_do_not_share_a_cache_entry_with_the_default(): void
    {
        $cache = new ArrayCache;

        $httpDefault = new ScriptedHttpClient;
        $httpDefault->queuePostResponse($this->openAiTextResponse('Answer default.', 12, 6));
        $providerDefault = new OpenAIProvider(apiKey: 'test-key', http: $httpDefault, model: 'gpt-4o-mini', name: 'openai');
        $clawDefault = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($providerDefault)
            ->responseCache($cache)
            ->build();
        $clawDefault->send("Search the web for today's exchange rate.");

        $httpCustom = new ScriptedHttpClient;
        $httpCustom->queuePostResponse($this->openAiTextResponse('Answer custom.', 12, 6));
        $providerCustom = new OpenAIProvider(apiKey: 'test-key', http: $httpCustom, model: 'gpt-4o-mini', name: 'openai');
        $clawCustom = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($providerCustom)
            ->withProviderTool(new WebSearch)
            ->withProviderTool(new WebSearch)
            ->responseCache($cache)
            ->build();
        $clawCustom->send("Search the web for today's exchange rate.");

        self::assertSame(1, $httpDefault->postCallCount);
        self::assertSame(1, $httpCustom->postCallCount, 'Custom provider tools must not share the default WebSearch cache entry.');
    }

    public function test_with_memory_and_history_off_a_second_identical_send_makes_zero_http_calls_and_reports_zero_tokens(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse('Your refund was processed on the 3rd.', 30, 12));

        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');
        $claw = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->responseCache(new ArrayCache)
            ->build();

        $first = $claw->send('When was my refund processed?');
        $second = $claw->send('When was my refund processed?');

        self::assertSame('Your refund was processed on the 3rd.', $first->text);
        self::assertSame('Your refund was processed on the 3rd.', $second->text);
        self::assertSame(1, $http->postCallCount);
        self::assertSame(0, $second->inputTokens);
        self::assertSame(0, $second->outputTokens);
    }

    public function test_with_memory_on_a_changed_history_is_a_cache_miss(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse('First reply.', 12, 6));
        $http->queuePostResponse($this->openAiTextResponse('Second reply.', 12, 6));

        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');
        $claw = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->memory(new ArrayMemory)
            ->responseCache(new ArrayCache)
            ->build();

        $conversation = $claw->conversation();
        $turn1 = $claw->sendInConversation($conversation, 'Summarise this ticket.');
        $turn2 = $claw->sendInConversation($turn1->conversation, 'Summarise this ticket.');

        self::assertSame('First reply.', $turn1->response->text);
        self::assertSame('Second reply.', $turn2->response->text);
        self::assertSame(2, $http->postCallCount);
    }

    public function test_provider_response_cached_fires_on_hit_and_cache_hit_does_not(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse('Answer.', 12, 6));

        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');
        $claw = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->responseCache(new ArrayCache)
            ->build();

        $cachedFired = null;
        $cacheHitFired = null;
        HookRegistry::on(LifecycleEvent::ProviderResponseCached->value, function (array $ctx) use (&$cachedFired): void {
            $cachedFired = $ctx;
        });
        HookRegistry::on(LifecycleEvent::ProviderCacheHit->value, function (array $ctx) use (&$cacheHitFired): void {
            $cacheHitFired = $ctx;
        });

        $claw->send('Summarise this ticket.');
        self::assertNull($cachedFired, 'provider.response_cached must not fire on the first, uncached call.');

        $claw->send('Summarise this ticket.');

        self::assertNotNull($cachedFired);
        self::assertSame('openai', $cachedFired['provider']);
        self::assertSame('gpt-4o-mini', $cachedFired['model']);
        self::assertNull($cacheHitFired, 'provider.cache_hit is the vendor prompt-cache event and must never fire for a response-cache hit.');
    }

    public function test_a_different_system_prompt_misses(): void
    {
        $cache = new ArrayCache;

        $httpA = new ScriptedHttpClient;
        $httpA->queuePostResponse($this->openAiTextResponse('Answer A.', 12, 6));
        $providerA = new OpenAIProvider(apiKey: 'test-key', http: $httpA, model: 'gpt-4o-mini');
        $clawA = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($providerA)
            ->systemPrompt('You are a support agent.')
            ->responseCache($cache)
            ->build();
        $clawA->send('Summarise this ticket.');

        $httpB = new ScriptedHttpClient;
        $httpB->queuePostResponse($this->openAiTextResponse('Answer B.', 12, 6));
        $providerB = new OpenAIProvider(apiKey: 'test-key', http: $httpB, model: 'gpt-4o-mini');
        $clawB = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($providerB)
            ->systemPrompt('You are a billing agent.')
            ->responseCache($cache)
            ->build();
        $clawB->send('Summarise this ticket.');

        self::assertSame(1, $httpA->postCallCount);
        self::assertSame(1, $httpB->postCallCount);
    }

    public function test_same_call_before_and_after_a_real_500_fallback_hits_the_same_entry(): void
    {
        $cache = new ArrayCache;

        $httpPrimary1 = new ScriptedHttpClient;
        $httpFallback1 = new ScriptedHttpClient;
        $httpPrimary1->queuePostException(new ProviderException('Provider returned HTTP 500: overloaded', 500));
        $httpFallback1->queuePostResponse($this->openAiTextResponse('Answer from groq.', 12, 6));

        $primary1 = new OpenAIProvider(apiKey: 'test-key', http: $httpPrimary1, model: 'gpt-4o-mini', name: 'openai');
        $fallback1 = new OpenAIProvider(apiKey: 'test-key', http: $httpFallback1, model: 'llama-3.1-8b-instant', name: 'groq');

        $clawFirst = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($primary1)
            ->withFallback($fallback1)
            ->responseCache($cache)
            ->build();

        $first = $clawFirst->send('Summarise this ticket.');

        self::assertSame('Answer from groq.', $first->text);
        self::assertSame(1, $httpPrimary1->postCallCount);
        self::assertSame(1, $httpFallback1->postCallCount);

        $httpPrimary2 = new ScriptedHttpClient;
        $httpFallback2 = new ScriptedHttpClient;
        $primary2 = new OpenAIProvider(apiKey: 'test-key', http: $httpPrimary2, model: 'gpt-4o-mini', name: 'openai');
        $fallback2 = new OpenAIProvider(apiKey: 'test-key', http: $httpFallback2, model: 'llama-3.1-8b-instant', name: 'groq');

        $clawSecond = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($primary2)
            ->withFallback($fallback2)
            ->responseCache($cache)
            ->build();

        $second = $clawSecond->send('Summarise this ticket.');

        self::assertSame('Answer from groq.', $second->text);
        self::assertSame(0, $httpPrimary2->postCallCount);
        self::assertSame(0, $httpFallback2->postCallCount);
    }

    public function test_structured_and_plain_calls_with_the_same_message_do_not_share_a_cache_entry(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse('Plain answer.', 12, 6));
        $http->queuePostResponse($this->openAiTextResponse((string) json_encode(['order_id' => 'A-1042', 'total' => 87.5]), 20, 10));

        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini', name: 'openai', nativeStructuredOutput: true);
        $claw = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->responseCache(new ArrayCache)
            ->build();

        $plain = $claw->send('Summarise this ticket.');
        $structured = $claw->sendStructured('Summarise this ticket.', $this->goalSchema());

        self::assertSame('Plain answer.', $plain->text);
        self::assertSame(['order_id' => 'A-1042', 'total' => 87.5], $structured->data);
        self::assertSame(1, $structured->raw->iterations);
        self::assertSame(2, $http->postCallCount);

        $structuredAgain = $claw->sendStructured('Summarise this ticket.', $this->goalSchema());

        self::assertSame(['order_id' => 'A-1042', 'total' => 87.5], $structuredAgain->data);
        self::assertSame(2, $http->postCallCount, 'A second identical structured call must be a cache hit.');
    }

    public function test_a_cached_tool_call_reply_still_runs_the_tool(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->toolCallResponse('call_1', '100', 40, 10));
        $http->queuePostResponse($this->openAiTextResponse('Order 100 confirmed delivered.', 50, 10));

        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');
        $tool = $this->makeCountingOrderTool();

        $claw = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->addTool($tool)
            ->responseCache(new ArrayCache)
            ->build();

        $first = $claw->send('Fetch order 100 status.');

        self::assertSame('Order 100 confirmed delivered.', $first->text);
        self::assertSame(2, $http->postCallCount);
        self::assertSame(1, $tool->count);

        $second = $claw->send('Fetch order 100 status.');

        self::assertSame('Order 100 confirmed delivered.', $second->text);
        self::assertSame(2, $http->postCallCount, 'Both the tool-call turn and the follow-up turn must be served from cache.');
        self::assertSame(2, $tool->count, 'The cached tool_use reply must still run the real tool.');
    }

    public function test_stream_fast_path_with_no_tools_is_never_cached(): void
    {
        $http = new ScriptedHttpClient;
        $http->queueStreamLines([
            'data: {"choices":[{"delta":{"content":"Hello "}}]}',
            'data: {"choices":[{"delta":{"content":"there."}}]}',
            'data: [DONE]',
        ]);
        $http->queueStreamLines([
            'data: {"choices":[{"delta":{"content":"Hello "}}]}',
            'data: {"choices":[{"delta":{"content":"there."}}]}',
            'data: [DONE]',
        ]);

        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');
        $claw = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->responseCache(new ArrayCache)
            ->build();

        $collected1 = '';
        $claw->stream('Summarise this ticket.', function (string $token) use (&$collected1): void {
            $collected1 .= $token;
        });
        $collected2 = '';
        $claw->stream('Summarise this ticket.', function (string $token) use (&$collected2): void {
            $collected2 .= $token;
        });

        self::assertSame('Hello there.', $collected1);
        self::assertSame('Hello there.', $collected2);
        self::assertSame(2, $http->streamCallCount);
        self::assertSame(0, $http->postCallCount);
    }

    public function test_a_streamed_run_with_tools_goes_through_send_and_is_cached(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse('Answer.', 12, 6));

        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');
        $claw = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->addTool($this->makeCountingOrderTool())
            ->responseCache(new ArrayCache)
            ->build();

        $collected1 = '';
        $claw->stream('Summarise this ticket.', function (string $token) use (&$collected1): void {
            $collected1 .= $token;
        });
        $collected2 = '';
        $claw->stream('Summarise this ticket.', function (string $token) use (&$collected2): void {
            $collected2 .= $token;
        });

        self::assertSame('Answer.', $collected1);
        self::assertSame('Answer.', $collected2);
        self::assertSame(1, $http->postCallCount);
        self::assertSame(0, $http->streamCallCount);
    }

    public function test_the_configured_ttl_reaches_the_cache(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse('Answer.', 12, 6));

        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');
        $cache = new ArrayCache;

        $claw = Claw::builder()
            ->provider('openai')->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->responseCache($cache, 120)
            ->build();

        $claw->send('Summarise this ticket.');

        self::assertSame(1, $cache->setCallCount);
        self::assertSame(120, array_values($cache->setTtls)[0]);
    }
}
