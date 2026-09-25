<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\EventPayload;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for LLM provider lifecycle events.
 */
final class ProviderEventDispatcher
{
    /**
     * Fires immediately before each provider API call.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $historyLen  History length sent to the provider.
     * @param  int  $toolCount  Number of tools sent to the provider.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function request(
        string $provider,
        string $model,
        int $historyLen,
        int $toolCount,
        bool $streaming = false,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::ProviderRequest->value,
            [
                'provider' => $provider,
                'model' => $model,
                'history_len' => $historyLen,
                'tool_count' => $toolCount,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
            streaming: $streaming,
        );
    }

    /**
     * Fires immediately after a successful provider API response is received.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $type  Response type.
     * @param  int|null  $inputTokens  Input token count, if reported.
     * @param  int|null  $outputTokens  Output token count, if reported.
     * @param  int|null  $cacheReadTokens  Cache-read token count, if reported.
     * @param  int|null  $cacheWriteTokens  Cache-write token count, if reported.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function response(
        string $provider,
        string $model,
        string $type,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $cacheReadTokens = null,
        ?int $cacheWriteTokens = null,
        bool $streaming = false,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::ProviderResponse->value,
            [
                'provider' => $provider,
                'model' => $model,
                'type' => $type,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cache_read_tokens' => $cacheReadTokens,
                'cache_write_tokens' => $cacheWriteTokens,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
            streaming: $streaming,
        );
    }

    /**
     * Fires before each retry attempt after a transient ProviderException.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $attempt  Retry attempt number.
     * @param  string  $error  Error message.
     * @param  int  $iteration  ReAct loop iteration number.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function retry(
        string $provider,
        string $model,
        int $attempt,
        string $error,
        int $iteration,
        bool $streaming = false,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::ProviderRetry->value,
            [
                'provider' => $provider,
                'model' => $model,
                'attempt' => $attempt,
                'error' => $error,
                'iteration' => $iteration,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
            streaming: $streaming,
        );
    }

    /**
     * Fires when Anthropic prompt caching serves tokens from the cache.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  int  $cacheReadTokens  Cache-read token count.
     * @param  int|null  $cacheWriteTokens  Cache-write token count, if reported.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function cacheHit(
        string $provider,
        string $model,
        int $cacheReadTokens,
        ?int $cacheWriteTokens = null,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::ProviderCacheHit->value,
            [
                'provider' => $provider,
                'model' => $model,
                'cache_read_tokens' => $cacheReadTokens,
                'cache_write_tokens' => $cacheWriteTokens,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }

    /**
     * Fires when a provider API call returns an error that is not retried.
     *
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @param  string  $error  Error message.
     * @param  int  $iteration  ReAct loop iteration number.
     * @param  bool  $streaming  Whether the request is streaming.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function error(
        string $provider,
        string $model,
        string $error,
        int $iteration,
        bool $streaming = false,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::ProviderError->value,
            [
                'provider' => $provider,
                'model' => $model,
                'error' => $error,
                'iteration' => $iteration,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
            streaming: $streaming,
        );
    }

    /**
     * Fires for each text token as it arrives during true SSE streaming.
     *
     * @param  string  $token  Streamed token text.
     * @param  string  $provider  Provider name.
     * @param  string  $model  Model identifier.
     * @return void
     */
    public static function token(string $token, string $provider, string $model): void
    {
        HookRegistry::fire(LifecycleEvent::ProviderToken->value, [
            'token' => $token,
            'provider' => $provider,
            'model' => $model,
        ]);
    }
}
