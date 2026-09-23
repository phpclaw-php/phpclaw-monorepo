<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * Immutable value object returned by PhpClaw::send(), stream(), and sendInConversation().
 */
final class AgentResponse
{
    /**
     * Create a new AgentResponse instance.
     *
     * @param  string  $text  Final assistant text.
     * @param  string  $provider  Provider slug that generated the response.
     * @param  string  $model  Model identifier that generated the response.
     * @param  int  $iterations  Number of ReAct loop iterations taken.
     * @param  ?int  $inputTokens  Input tokens consumed, or null if the provider reported no usage.
     * @param  ?int  $outputTokens  Output tokens generated, or null if the provider reported no usage.
     * @param  int  $durationMs  Wall-clock duration of the run in milliseconds.
     * @param  string[]  $toolsCalled  Names of every tool invoked during the run.
     * @param  ?int  $cacheReadTokens  Tokens served from the provider's prompt cache, or null if unsupported.
     * @param  ?int  $cacheWriteTokens  Tokens written to the provider's prompt cache, or null if unsupported.
     * @param  ?string  $thinking  Extended-thinking output, or null if not requested/returned.
     * @param  string  $runId  ULID correlating this response's lifecycle events.
     * @return void
     */
    public function __construct(
        public readonly string $text,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $iterations,
        public readonly ?int $inputTokens = null,
        public readonly ?int $outputTokens = null,
        public readonly int $durationMs = 0,
        public readonly array $toolsCalled = [],
        public readonly ?int $cacheReadTokens = null,
        public readonly ?int $cacheWriteTokens = null,
        public readonly ?string $thinking = null,
        public readonly string $runId = '',
    ) {}

    /**
     * Whether the provider returned token usage data.
     *
     * @return bool True when both inputTokens and outputTokens are non-null.
     */
    public function hasUsage(): bool
    {
        return $this->inputTokens !== null && $this->outputTokens !== null;
    }

    /**
     * Total tokens used (input + output). Null if either is unavailable.
     *
     * @return int|null Sum of input + output tokens, or null when the provider returned no usage data.
     */
    public function totalTokens(): ?int
    {
        if (! $this->hasUsage()) {
            return null;
        }

        return $this->inputTokens + $this->outputTokens;
    }

    /**
     * Whether this response was (partially or fully) served from the Anthropic prompt cache.
     *
     * @return bool True when cacheReadTokens is greater than zero.
     */
    public function cacheHit(): bool
    {
        return ($this->cacheReadTokens ?? 0) > 0;
    }

    /**
     * Whether any tools were called during this run.
     *
     * @return bool True when the toolsCalled list is non-empty.
     */
    public function usedTools(): bool
    {
        return $this->toolsCalled !== [];
    }

    /**
     * Unique tool names called during this run (no duplicates), preserving order.
     *
     * @return string[]
     */
    public function uniqueToolsCalled(): array
    {
        return array_values(array_unique($this->toolsCalled));
    }

    /**
     * Whether this response includes extended thinking content.
     *
     * @return bool True when thinking is non-null and non-empty (Anthropic Sonnet/Opus only).
     */
    public function hasThinking(): bool
    {
        return $this->thinking !== null && $this->thinking !== '';
    }

    /**
     * Whether the agent used the ReAct loop (more than one provider round-trip).
     *
     * @return bool True when iterations is greater than 1, indicating tools were invoked.
     */
    public function isMultiStep(): bool
    {
        return $this->iterations > 1;
    }

    /**
     * Snapshot every public property as a plain associative array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'provider' => $this->provider,
            'model' => $this->model,
            'iterations' => $this->iterations,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'duration_ms' => $this->durationMs,
            'tools_called' => $this->toolsCalled,
            'cache_read_tokens' => $this->cacheReadTokens,
            'cache_write_tokens' => $this->cacheWriteTokens,
            'thinking' => $this->thinking,
            'run_id' => $this->runId,
        ];
    }

    /**
     * Convenience cast: allows (string) $response in templates.
     *
     * @return string The final text response (same as the $text property).
     */
    public function __toString(): string
    {
        return $this->text;
    }
}
