<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\AgentResponse;
use PHPUnit\Framework\TestCase;

final class AgentResponseTest extends TestCase
{
    public function test_constructor_sets_all_properties(): void
    {
        $response = new AgentResponse(
            text: 'Hello!',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            inputTokens: 50,
            outputTokens: 20,
        );

        $this->assertSame('Hello!', $response->text);
        $this->assertSame('anthropic', $response->provider);
        $this->assertSame('claude-haiku-4-5-20251001', $response->model);
        $this->assertSame(1, $response->iterations);
        $this->assertSame(50, $response->inputTokens);
        $this->assertSame(20, $response->outputTokens);
    }

    public function test_total_tokens_returns_sum_when_both_present(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'openai',
            model: 'gpt-4o-mini',
            iterations: 1,
            inputTokens: 30,
            outputTokens: 10,
        );

        $this->assertSame(40, $response->totalTokens());
    }

    public function test_total_tokens_returns_null_when_input_missing(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'groq',
            model: 'llama-3.1-8b-instant',
            iterations: 1,
            inputTokens: null,
            outputTokens: 10,
        );

        $this->assertNull($response->totalTokens());
    }

    public function test_total_tokens_returns_null_when_both_missing(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertNull($response->totalTokens());
    }

    public function test_to_string_returns_text(): void
    {
        $response = new AgentResponse(
            text: 'The answer is 42.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertSame('The answer is 42.', (string) $response);
    }

    public function test_response_is_readonly(): void
    {
        $response = new AgentResponse(
            text: 'test',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->expectException(\Error::class);
        $response->text = 'modified';
    }

    public function test_optional_tokens_default_to_null(): void
    {
        $response = new AgentResponse(
            text: 'Hello',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 2,
        );

        $this->assertNull($response->inputTokens);
        $this->assertNull($response->outputTokens);
    }

    public function test_duration_ms_defaults_to_zero(): void
    {
        $response = new AgentResponse(
            text: 'Hello',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertSame(0, $response->durationMs);
    }

    public function test_duration_ms_is_set_when_provided(): void
    {
        $response = new AgentResponse(
            text: 'Hello',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            durationMs: 342,
        );

        $this->assertSame(342, $response->durationMs);
    }

    public function test_tools_called_defaults_to_empty_array(): void
    {
        $response = new AgentResponse(
            text: 'Hello',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertSame([], $response->toolsCalled);
    }

    public function test_tools_called_is_set_when_provided(): void
    {
        $response = new AgentResponse(
            text: 'Hello',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 3,
            toolsCalled: ['shell', 'http', 'shell'],
        );

        $this->assertSame(['shell', 'http', 'shell'], $response->toolsCalled);
    }

    public function test_used_tools_returns_false_when_no_tools_called(): void
    {
        $response = new AgentResponse(
            text: 'Hello',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertFalse($response->usedTools());
    }

    public function test_used_tools_returns_true_when_tools_were_called(): void
    {
        $response = new AgentResponse(
            text: 'Done',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 2,
            toolsCalled: ['shell'],
        );

        $this->assertTrue($response->usedTools());
    }

    public function test_unique_tools_called_deduplicates(): void
    {
        $response = new AgentResponse(
            text: 'Done',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 4,
            toolsCalled: ['shell', 'http', 'shell', 'file', 'http'],
        );

        $this->assertSame(['shell', 'http', 'file'], $response->uniqueToolsCalled());
    }

    public function test_unique_tools_called_returns_empty_when_no_tools(): void
    {
        $response = new AgentResponse(
            text: 'Hello',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertSame([], $response->uniqueToolsCalled());
    }

    public function test_cache_read_tokens_defaults_to_null(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertNull($response->cacheReadTokens);
    }

    public function test_cache_write_tokens_defaults_to_null(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertNull($response->cacheWriteTokens);
    }

    public function test_cache_hit_returns_false_when_cache_read_tokens_is_null(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertFalse($response->cacheHit());
    }

    public function test_cache_hit_returns_false_when_cache_read_tokens_is_zero(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            cacheReadTokens: 0,
        );

        $this->assertFalse($response->cacheHit());
    }

    public function test_cache_hit_returns_true_when_cache_read_tokens_is_positive(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            cacheReadTokens: 512,
        );

        $this->assertTrue($response->cacheHit());
    }

    public function test_cache_write_tokens_is_set_when_provided(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            cacheWriteTokens: 1024,
        );

        $this->assertSame(1024, $response->cacheWriteTokens);
    }

    public function test_thinking_defaults_to_null(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertNull($response->thinking);
    }

    public function test_has_thinking_returns_false_when_thinking_is_null(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
        );

        $this->assertFalse($response->hasThinking());
    }

    public function test_has_thinking_returns_false_when_thinking_is_empty_string(): void
    {
        $response = new AgentResponse(
            text: 'Hi',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 1,
            thinking: '',
        );

        $this->assertFalse($response->hasThinking());
    }

    public function test_has_thinking_returns_true_when_thinking_is_non_empty(): void
    {
        $response = new AgentResponse(
            text: 'The answer is 42.',
            provider: 'anthropic',
            model: 'claude-sonnet-5',
            iterations: 1,
            thinking: 'Let me reason through this step by step...',
        );

        $this->assertTrue($response->hasThinking());
        $this->assertSame('Let me reason through this step by step...', $response->thinking);
    }

    public function test_has_usage_returns_true_when_both_token_counts_present(): void
    {
        $response = new AgentResponse(
            text: 'x', provider: 'anthropic', model: 'claude-haiku-4-5', iterations: 1,
            inputTokens: 50, outputTokens: 10,
        );

        $this->assertTrue($response->hasUsage());
    }

    public function test_has_usage_returns_false_when_input_tokens_missing(): void
    {
        $response = new AgentResponse(
            text: 'x', provider: 'anthropic', model: 'claude-haiku-4-5', iterations: 1,
            inputTokens: null, outputTokens: 10,
        );

        $this->assertFalse($response->hasUsage());
    }

    public function test_has_usage_returns_false_when_output_tokens_missing(): void
    {
        $response = new AgentResponse(
            text: 'x', provider: 'anthropic', model: 'claude-haiku-4-5', iterations: 1,
            inputTokens: 50, outputTokens: null,
        );

        $this->assertFalse($response->hasUsage());
    }

    public function test_is_multi_step_returns_false_for_single_iteration(): void
    {
        $response = new AgentResponse(
            text: 'x', provider: 'anthropic', model: 'claude-haiku-4-5', iterations: 1,
        );

        $this->assertFalse($response->isMultiStep());
    }

    public function test_is_multi_step_returns_true_when_iterations_exceed_one(): void
    {
        $response = new AgentResponse(
            text: 'x', provider: 'anthropic', model: 'claude-haiku-4-5', iterations: 3,
        );

        $this->assertTrue($response->isMultiStep());
    }

    public function test_to_array_snapshots_every_property(): void
    {
        $response = new AgentResponse(
            text: 'final text',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            iterations: 2,
            inputTokens: 120,
            outputTokens: 45,
            durationMs: 850,
            toolsCalled: ['shell', 'http'],
            cacheReadTokens: 100,
            cacheWriteTokens: 50,
            thinking: 'reasoning text',
            runId: '01HQK3X9YN7Z4P5W2V8M3T6QF1',
        );

        $this->assertSame(
            [
                'text' => 'final text',
                'provider' => 'anthropic',
                'model' => 'claude-haiku-4-5-20251001',
                'iterations' => 2,
                'input_tokens' => 120,
                'output_tokens' => 45,
                'duration_ms' => 850,
                'tools_called' => ['shell', 'http'],
                'cache_read_tokens' => 100,
                'cache_write_tokens' => 50,
                'thinking' => 'reasoning text',
                'run_id' => '01HQK3X9YN7Z4P5W2V8M3T6QF1',
            ],
            $response->toArray(),
        );
    }

    public function test_to_array_preserves_nullable_token_fields_as_null(): void
    {
        $response = new AgentResponse(
            text: 'x',
            provider: 'openai',
            model: 'gpt-4o-mini',
            iterations: 1,
        );

        $arr = $response->toArray();
        $this->assertNull($arr['input_tokens']);
        $this->assertNull($arr['output_tokens']);
        $this->assertNull($arr['cache_read_tokens']);
        $this->assertNull($arr['cache_write_tokens']);
        $this->assertNull($arr['thinking']);
        $this->assertSame('', $arr['run_id']);
    }
}
