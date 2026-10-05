<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Flow;

use PhpClaw\Claw;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Flow\Support\ArrayCache;
use PhpClaw\Tests\Unit\Flow\Support\ScriptedHttpClient;
use PHPUnit\Framework\TestCase;

final class ThrottleFlowTest extends TestCase
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

    public function test_a_send_with_a_full_bucket_reaches_the_http_client_with_no_sleep(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse('Answer.', 12, 6));
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini', name: 'openai');

        $claw = Claw::builder()
            ->provider('openai')
            ->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->rateLimit(60)
            ->build();

        $response = $claw->send('Summarise this ticket.');

        self::assertSame('Answer.', $response->text);
        self::assertSame(1, $http->postCallCount);
    }

    public function test_a_second_send_beyond_the_bucket_throws_when_max_wait_ms_is_zero(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse('Answer.', 12, 6));
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini', name: 'openai');

        $claw = Claw::builder()
            ->provider('openai')
            ->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->rateLimit(1, maxWaitMs: 0)
            ->build();

        $claw->send('First message.');

        try {
            $claw->send('Second message.');
            self::fail('Expected ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame(429, $e->statusCode);
            self::assertStringContainsString('rate limit wait exceeded', $e->getMessage());
        }

        self::assertSame(1, $http->postCallCount);
    }

    public function test_without_rate_limit_configured_the_provider_is_used_directly(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse('Answer.', 12, 6));
        $http->queuePostResponse($this->openAiTextResponse('Answer again.', 12, 6));
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini', name: 'openai');

        $claw = Claw::builder()
            ->provider('openai')
            ->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->build();

        $first = $claw->send('First message.');
        $second = $claw->send('Second message.');

        self::assertSame('Answer.', $first->text);
        self::assertSame('Answer again.', $second->text);
        self::assertSame(2, $http->postCallCount);
    }

    public function test_a_response_cache_hit_takes_no_throttle_token(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostResponse($this->openAiTextResponse('Answer.', 12, 6));
        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');

        $claw = Claw::builder()
            ->provider('openai')
            ->model('gpt-4o-mini')
            ->providerOverride($provider)
            ->responseCache(new ArrayCache)
            ->rateLimit(1, maxWaitMs: 0)
            ->build();

        $first = $claw->send('First message.');
        $second = $claw->send('First message.');

        self::assertSame('Answer.', $first->text);
        self::assertSame('Answer.', $second->text);
        self::assertSame(1, $http->postCallCount);
    }

    public function test_two_claws_sharing_a_rate_limit_store_share_the_bucket_across_requests(): void
    {
        $store = new ArrayCache;
        $http1 = new ScriptedHttpClient;
        $http1->queuePostResponse($this->openAiTextResponse('Answer.', 12, 6));
        $provider1 = new OpenAIProvider(apiKey: 'test-key', http: $http1, model: 'gpt-4o-mini', name: 'openai');

        $firstClaw = Claw::builder()
            ->provider('openai')
            ->model('gpt-4o-mini')
            ->providerOverride($provider1)
            ->rateLimit(1, maxWaitMs: 0, store: $store)
            ->build();

        $firstClaw->send('First message.');

        $http2 = new ScriptedHttpClient;
        $http2->queuePostResponse($this->openAiTextResponse('Answer again.', 12, 6));
        $provider2 = new OpenAIProvider(apiKey: 'test-key', http: $http2, model: 'gpt-4o-mini', name: 'openai');

        $secondClaw = Claw::builder()
            ->provider('openai')
            ->model('gpt-4o-mini')
            ->providerOverride($provider2)
            ->rateLimit(1, maxWaitMs: 0, store: $store)
            ->build();

        try {
            $secondClaw->send('Second message.');
            self::fail('Expected ProviderException.');
        } catch (ProviderException $e) {
            self::assertStringContainsString('rate limit wait exceeded', $e->getMessage());
        }

        self::assertSame(0, $http2->postCallCount);
    }
}
