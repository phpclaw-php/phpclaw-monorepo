<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Providers\Contracts\ProviderInterface;

/**
 * Wraps `ProviderInterface::send()` with automatic retry on transient `ProviderException`, firing `provider.retry` between attempts and `provider.error` after the final failure.
 *
 * @internal
 */
final class ProviderRetryLoop
{
    /**
     * Build a ProviderRetryLoop.
     *
     * @param  ProviderInterface  $provider  Provider whose send() call is retried.
     * @param  int  $maxRetries  Maximum retry attempts on transient failure.
     */
    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly int $maxRetries,
    ) {}

    /**
     * Call `provider->send()` with automatic retry on transient failure.
     *
     * @param  Message[]  $history  Conversation history sent to the provider.
     * @param  array<int, array<string, mixed>>  $toolSchemas  Tool schemas in the provider's native shape.
     * @param  int  $iteration  Current ReAct loop iteration number (passed to hooks).
     * @param  bool  $streaming  Whether this iteration is in streaming mode.
     * @param  string  $runId  Run identifier propagated to hooks for run correlation.
     * @param  string  $parentRunId  Parent run id for nesting retry/error hooks.
     * @return array<string, mixed> Raw provider response (text or tool_use_batch shape).
     *
     * @throws ProviderException When the provider fails after all retries or on a non-retryable hallucination rejection.
     */
    public function send(
        array $history,
        array $toolSchemas,
        int $iteration,
        bool $streaming = false,
        string $runId = '',
        string $parentRunId = '',
    ): array {
        $lastException = null;
        $maxAttempts = 1 + $this->maxRetries;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->provider->send($history, $toolSchemas);
            } catch (ProviderException $e) {
                if ($this->isHallucinationRejection($e)) {
                    throw $e;
                }

                $lastException = $e;

                if ($attempt < $maxAttempts) {
                    HookDispatcher::providerRetry(
                        provider: $this->provider->name(),
                        model: $this->provider->model(),
                        attempt: $attempt,
                        error: $e->getMessage(),
                        iteration: $iteration,
                        streaming: $streaming,
                        runId: $runId,
                        parentRunId: $parentRunId,
                    );
                }
            }
        }

        HookDispatcher::providerError(
            provider: $this->provider->name(),
            model: $this->provider->model(),
            error: $lastException->getMessage(),
            iteration: $iteration,
            streaming: $streaming,
            runId: $runId,
            parentRunId: $parentRunId,
        );

        throw $lastException;
    }

    /**
     * Detect provider-side rejections caused by hallucinated tool names.
     *
     * @param  ProviderException  $e  Exception to classify.
     * @return bool True on success.
     */
    public function isHallucinationRejection(ProviderException $e): bool
    {
        $msg = $e->getMessage();

        return str_contains($msg, 'tool call validation failed')
            || str_contains($msg, 'not in request.tools')
            || str_contains($msg, 'Failed to call a function');
    }
}
