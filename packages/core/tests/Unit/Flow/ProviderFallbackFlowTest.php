<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Flow;

use PhpClaw\Claw;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;
use PhpClaw\Providers\AnthropicProvider;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tests\Unit\Flow\Support\ScriptedHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderFallbackFlowTest extends TestCase
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

    private function anthropicTextResponse(string $text, int $inputTokens, int $outputTokens): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }

    private function vendorError(int $status, array $errorBody): ProviderException
    {
        $error = $errorBody['error'] ?? null;
        $message = is_array($error) ? (string) ($error['message'] ?? "HTTP {$status}") : (string) ($error ?? "HTTP {$status}");

        return new ProviderException("Provider returned HTTP {$status}: {$message}", $status);
    }

    private function buildOpenAiFallbackClaw(ScriptedHttpClient $httpPrimary, ScriptedHttpClient $httpFallback): Claw
    {
        $primary = new OpenAIProvider(apiKey: 'test-key', http: $httpPrimary, model: 'gpt-4o-mini', name: 'openai');
        $fallback = new OpenAIProvider(apiKey: 'test-key', http: $httpFallback, model: 'llama-3.1-8b-instant', name: 'groq');

        return Claw::builder()
            ->provider('openai')
            ->model('gpt-4o-mini')
            ->providerOverride($primary)
            ->withFallback($fallback)
            ->build();
    }

    public function test_a_500_on_the_primary_fails_over_to_the_fallback_and_the_response_names_it(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queuePostException($this->vendorError(500, ['error' => ['type' => 'server_error', 'message' => 'The server had an error processing your request.']]));
        $httpFallback->queuePostResponse($this->openAiTextResponse('Answer from groq.', 12, 6));

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);
        $response = $claw->send('Summarise this ticket.');

        self::assertSame('Answer from groq.', $response->text);
        self::assertSame('groq', $response->provider);
        self::assertSame(1, $httpPrimary->postCallCount);
        self::assertSame(1, $httpFallback->postCallCount);
    }

    public function test_a_429_on_the_primary_fails_over_to_the_fallback(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queuePostException($this->vendorError(429, ['error' => ['type' => 'rate_limit_error', 'message' => 'Rate limit reached.']]));
        $httpFallback->queuePostResponse($this->openAiTextResponse('Answer from groq.', 12, 6));

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);
        $response = $claw->send('Summarise this ticket.');

        self::assertSame('groq', $response->provider);
        self::assertSame(1, $httpPrimary->postCallCount);
        self::assertSame(1, $httpFallback->postCallCount);
    }

    public function test_a_transport_timeout_with_status_zero_fails_over(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queuePostException(new ProviderException('cURL error: Operation timed out after 5000 milliseconds.'));
        $httpFallback->queuePostResponse($this->openAiTextResponse('Answer from groq.', 12, 6));

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);
        $response = $claw->send('Summarise this ticket.');

        self::assertSame('groq', $response->provider);
        self::assertSame(1, $httpFallback->postCallCount);
    }

    public function test_an_html_body_502_bad_gateway_status_fails_over(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queuePostException(new ProviderException('Provider returned HTTP 502: 502 Bad Gateway nginx', 502));
        $httpFallback->queuePostResponse($this->openAiTextResponse('Answer from groq.', 12, 6));

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);
        $response = $claw->send('Summarise this ticket.');

        self::assertSame('groq', $response->provider);
        self::assertSame(1, $httpFallback->postCallCount);
    }

    public static function nonFailoverStatuses(): iterable
    {
        yield '401 unauthorized' => [401, 'Invalid API key provided.'];
        yield '403 forbidden' => [403, 'You do not have access to this model.'];
        yield '400 bad request' => [400, "Invalid value for 'temperature'."];
    }

    #[DataProvider('nonFailoverStatuses')]
    public function test_a_non_failover_eligible_status_surfaces_the_primary_error_without_calling_the_fallback(int $status, string $vendorMessage): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queuePostException($this->vendorError($status, ['error' => ['message' => $vendorMessage]]));

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);

        try {
            $claw->send('Summarise this ticket.');
            self::fail('Expected ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame($status, $e->statusCode);
            self::assertStringContainsString($vendorMessage, $e->getMessage());
        }

        self::assertSame(1, $httpPrimary->postCallCount);
        self::assertSame(0, $httpFallback->postCallCount);
    }

    public function test_an_html_body_401_status_does_not_fail_over(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queuePostException(new ProviderException('Provider returned HTTP 401: 401 Unauthorized', 401));

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);

        try {
            $claw->send('Summarise this ticket.');
            self::fail('Expected ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame(401, $e->statusCode);
        }

        self::assertSame(0, $httpFallback->postCallCount);
    }

    public function test_every_provider_failing_throws_a_combined_exception_listing_every_attempt(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queuePostException($this->vendorError(500, ['error' => ['message' => 'Overloaded.']]));
        $fallbackFailure = $this->vendorError(503, ['error' => ['message' => 'Service unavailable.']]);
        $httpFallback->queuePostException($fallbackFailure);

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);

        try {
            $claw->send('Summarise this ticket.');
            self::fail('Expected ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame(503, $e->statusCode);
            self::assertSame($fallbackFailure, $e->getPrevious());
            self::assertStringContainsString('openai/gpt-4o-mini (status 500)', $e->getMessage());
            self::assertStringContainsString('groq/llama-3.1-8b-instant (status 503)', $e->getMessage());
        }

        self::assertSame(1, $httpPrimary->postCallCount);
        self::assertSame(1, $httpFallback->postCallCount);
    }

    public function test_stream_with_every_provider_failing_before_any_token_throws_a_combined_exception_listing_every_attempt(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queueStreamException($this->vendorError(500, ['error' => ['message' => 'Overloaded.']]));
        $fallbackFailure = $this->vendorError(503, ['error' => ['message' => 'Service unavailable.']]);
        $httpFallback->queueStreamException($fallbackFailure);

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);

        try {
            $claw->stream('Summarise this ticket.', function (string $token): void {});
            self::fail('Expected ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame(503, $e->statusCode);
            self::assertSame($fallbackFailure, $e->getPrevious());
            self::assertStringContainsString('openai/gpt-4o-mini (status 500)', $e->getMessage());
            self::assertStringContainsString('groq/llama-3.1-8b-instant (status 503)', $e->getMessage());
        }

        self::assertSame(1, $httpPrimary->streamCallCount);
        self::assertSame(1, $httpFallback->streamCallCount);
    }

    public function test_fallback_fires_the_provider_fallback_event_with_from_to_and_status(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queuePostException($this->vendorError(500, ['error' => ['message' => 'Overloaded.']]));
        $httpFallback->queuePostResponse($this->openAiTextResponse('Answer from groq.', 12, 6));

        $captured = null;
        HookRegistry::on(LifecycleEvent::ProviderFallback->value, function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);
        $claw->send('Summarise this ticket.');

        self::assertNotNull($captured);
        self::assertSame('openai', $captured['from']);
        self::assertSame('groq', $captured['to']);
        self::assertSame(500, $captured['status']);
    }

    public function test_stream_fails_over_before_the_first_token_reaches_on_token(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queueStreamException($this->vendorError(500, ['error' => ['message' => 'Overloaded.']]));
        $httpFallback->queueStreamLines([
            'data: {"choices":[{"delta":{"content":"Answer "}}]}',
            'data: {"choices":[{"delta":{"content":"from groq."}}]}',
            'data: [DONE]',
        ]);

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);

        $collected = '';
        $response = $claw->stream('Summarise this ticket.', function (string $token) use (&$collected): void {
            $collected .= $token;
        });

        self::assertSame('Answer from groq.', $collected);
        self::assertSame('groq', $response->provider);
        self::assertSame(1, $httpFallback->streamCallCount);
    }

    public function test_stream_does_not_fail_over_once_a_token_was_already_delivered(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queueStreamLinesThenException(
            ['data: {"choices":[{"delta":{"content":"Partial "}}]}'],
            $this->vendorError(500, ['error' => ['message' => 'Overloaded mid-stream.']]),
        );

        $claw = $this->buildOpenAiFallbackClaw($httpPrimary, $httpFallback);

        $collected = '';
        try {
            $claw->stream('Summarise this ticket.', function (string $token) use (&$collected): void {
                $collected .= $token;
            });
            self::fail('Expected ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame(500, $e->statusCode);
        }

        self::assertSame('Partial ', $collected);
        self::assertSame(0, $httpFallback->streamCallCount);
    }

    public function test_web_search_is_attached_to_both_the_primary_and_the_fallback_provider(): void
    {
        $httpPrimary = new ScriptedHttpClient;
        $httpFallback = new ScriptedHttpClient;
        $httpPrimary->queuePostException($this->vendorError(500, ['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded.']]));
        $httpFallback->queuePostResponse($this->anthropicTextResponse('Answer from the fallback model.', 12, 6));

        $primary = new AnthropicProvider(apiKey: 'test-key', http: $httpPrimary, model: 'claude-sonnet-5');
        $fallback = new AnthropicProvider(apiKey: 'test-key', http: $httpFallback, model: 'claude-haiku-4-5-20251001');

        $claw = Claw::builder()
            ->provider('anthropic')
            ->model('claude-sonnet-5')
            ->providerOverride($primary)
            ->withFallback($fallback)
            ->build();

        $response = $claw->send("Search the web for today's exchange rate.");

        self::assertSame('Answer from the fallback model.', $response->text);
        $this->assertWebSearchToolPresent($httpPrimary->postBodies[0]);
        $this->assertWebSearchToolPresent($httpFallback->postBodies[0]);
    }

    private function assertWebSearchToolPresent(array $body): void
    {
        self::assertArrayHasKey('tools', $body);

        $found = false;
        foreach ($body['tools'] as $tool) {
            if (($tool['type'] ?? null) === 'web_search_20250305') {
                $found = true;
            }
        }

        self::assertTrue($found, 'Expected a web_search_20250305 tool entry in the request body.');
    }

    public function test_a_mixed_tool_format_chain_throws_adapter_exception_at_build(): void
    {
        $primary = new AnthropicProvider(apiKey: 'test-key', http: new ScriptedHttpClient);
        $fallback = new OpenAIProvider(apiKey: 'test-key', http: new ScriptedHttpClient, name: 'openai');

        $this->expectException(AdapterException::class);

        Claw::builder()
            ->provider('anthropic')
            ->providerOverride($primary)
            ->withFallback($fallback)
            ->build();
    }

    public function test_with_no_fallbacks_configured_the_provider_is_used_directly_and_a_failure_never_fires_fallback(): void
    {
        $http = new ScriptedHttpClient;
        $http->queuePostException($this->vendorError(500, ['error' => ['message' => 'Overloaded.']]));

        $captured = null;
        HookRegistry::on(LifecycleEvent::ProviderFallback->value, function (array $ctx) use (&$captured): void {
            $captured = $ctx;
        });

        $provider = new OpenAIProvider(apiKey: 'test-key', http: $http, model: 'gpt-4o-mini');
        $claw = Claw::builder()->provider('openai')->model('gpt-4o-mini')->providerOverride($provider)->build();

        try {
            $claw->send('Summarise this ticket.');
            self::fail('Expected ProviderException.');
        } catch (ProviderException $e) {
            self::assertSame(500, $e->statusCode);
            self::assertStringContainsString('Overloaded.', $e->getMessage());
        }

        self::assertSame(1, $http->postCallCount);
        self::assertNull($captured);
    }
}
