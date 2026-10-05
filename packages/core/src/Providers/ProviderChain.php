<?php

declare(strict_types=1);

namespace PhpClaw\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Contracts\SupportsStructuredOutputInterface;
use PhpClaw\Tools\ToolRegistry;

/**
 * Decorator that tries each inner provider in order, failing over to the next on a failover-eligible
 * error; every provider in the chain must share the same ToolRegistry::toolFormat().
 */
final class ProviderChain implements ProviderInterface, SupportsStructuredOutputInterface
{
    private const FAILOVER_STATUS_TOO_MANY_REQUESTS = 429;

    private const FAILOVER_STATUS_SERVER_ERROR_MIN = 500;

    private int $lastServedIndex = 0;

    /**
     * Build a chain that tries every provider in order on a failover-eligible ProviderException.
     *
     * @param  non-empty-list<ProviderInterface>  $providers  Providers tried in order; every provider must resolve to the same ToolRegistry::toolFormat().
     * @return void
     *
     * @throws AdapterException When the list is empty, or two providers resolve to a different tool format.
     */
    public function __construct(
        private readonly array $providers,
    ) {
        if ($this->providers === []) {
            throw new AdapterException('ProviderChain requires at least one provider.');
        }

        $format = ToolRegistry::toolFormat($this->providers[0]->name());

        foreach ($this->providers as $provider) {
            if (ToolRegistry::toolFormat($provider->name()) !== $format) {
                throw new AdapterException('ProviderChain requires every provider to share the same tool format.');
            }
        }
    }

    /**
     * Send through the first provider that succeeds; ProviderRetryLoop retries this whole call, so one
     * retry attempt already tries every provider in the chain once.
     *
     * @param  array<int, Message>  $messages  Full conversation history so far.
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas in provider-native format.
     * @return array{type: string, calls?: array<int,array<string,mixed>>, text?: string, input_tokens?: int|null, output_tokens?: int|null, cache_read_tokens?: int|null, cache_write_tokens?: int|null, thinking?: string|null}
     *
     * @throws ProviderException When a non-failover-eligible error is reached, or every provider fails.
     */
    public function send(array $messages, array $tools = []): array
    {
        $attempts = [];
        $lastFailure = null;

        foreach ($this->providers as $index => $provider) {
            try {
                $response = $provider->send($messages, $tools);
                $this->lastServedIndex = $index;

                return $response;
            } catch (ProviderException $e) {
                if (! self::isFailoverEligible($e)) {
                    throw $e;
                }

                $attempts[] = self::describeAttempt($provider, $e);
                $lastFailure = $e;
                $this->fireFallback($index, $e->statusCode);
            }
        }

        throw new ProviderException(
            'Every provider in the chain failed: '.implode('; ', $attempts),
            $lastFailure->statusCode,
            previous: $lastFailure,
        );
    }

    /**
     * Stream from the first provider that succeeds, failing over only before its first token reaches $onToken.
     *
     * @param  array<int, Message>  $messages  Conversation history.
     * @param  callable(string):void  $onToken  Called with each text token as it arrives.
     * @return string Full assembled text from whichever provider served the call.
     *
     * @throws ProviderException When a token was already delivered, or every provider fails before yielding one.
     */
    public function stream(array $messages, callable $onToken): string
    {
        $attempts = [];
        $lastFailure = null;

        foreach ($this->providers as $index => $provider) {
            $tokenDelivered = false;
            $trackedOnToken = static function (string $token) use ($onToken, &$tokenDelivered): void {
                $tokenDelivered = true;
                $onToken($token);
            };

            try {
                $text = $provider->stream($messages, $trackedOnToken);
                $this->lastServedIndex = $index;

                return $text;
            } catch (ProviderException $e) {
                if ($tokenDelivered || ! self::isFailoverEligible($e)) {
                    throw $e;
                }

                $attempts[] = self::describeAttempt($provider, $e);
                $lastFailure = $e;
                $this->fireFallback($index, $e->statusCode);
            }
        }

        throw new ProviderException(
            'Every provider in the chain failed: '.implode('; ', $attempts),
            $lastFailure->statusCode,
            previous: $lastFailure,
        );
    }

    /**
     * Name of the provider that served the last successful call, or the first provider before any call.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->providers[$this->lastServedIndex]->name();
    }

    /**
     * Model of the provider that served the last successful call, or the first provider before any call.
     *
     * @return string
     */
    public function model(): string
    {
        return $this->providers[$this->lastServedIndex]->model();
    }

    /**
     * Whether every provider in the chain implements SupportsStructuredOutputInterface and reports true.
     *
     * @return bool
     */
    public function supportsResponseSchema(): bool
    {
        foreach ($this->providers as $provider) {
            if (! $provider instanceof SupportsStructuredOutputInterface || ! $provider->supportsResponseSchema()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return a new chain of the same length, wrapping every structured-output-capable inner provider in
     * its own withResponseSchema() clone; a provider that does not support it is carried through unchanged.
     *
     * @param  array<string, mixed>  $schema  JSON Schema every capable inner provider should enforce natively.
     * @return static
     */
    public function withResponseSchema(array $schema): static
    {
        return new self(array_map(
            static fn (ProviderInterface $provider): ProviderInterface => $provider instanceof SupportsStructuredOutputInterface
                ? $provider->withResponseSchema($schema)
                : $provider,
            $this->providers,
        ));
    }

    /**
     * Whether a ProviderException should trigger failover to the next provider in the chain.
     *
     * @param  ProviderException  $e  Exception raised by the current provider.
     * @return bool True for a transport failure (status 0), a 429, or any 5xx.
     */
    private static function isFailoverEligible(ProviderException $e): bool
    {
        return $e->statusCode === 0
            || $e->statusCode === self::FAILOVER_STATUS_TOO_MANY_REQUESTS
            || $e->statusCode >= self::FAILOVER_STATUS_SERVER_ERROR_MIN;
    }

    /**
     * Describe a failed attempt for the combined "every provider failed" exception message.
     *
     * @param  ProviderInterface  $provider  Provider that failed.
     * @param  ProviderException  $e  Exception it raised.
     * @return string
     */
    private static function describeAttempt(ProviderInterface $provider, ProviderException $e): string
    {
        return "{$provider->name()}/{$provider->model()} (status {$e->statusCode})";
    }

    /**
     * Fire provider.fallback when the provider that just failed is not the last in the chain.
     *
     * @param  int  $failedIndex  Index of the provider that just failed.
     * @param  int  $status  HTTP status (or 0 for a transport failure) that triggered the failover.
     * @return void
     */
    private function fireFallback(int $failedIndex, int $status): void
    {
        if ($failedIndex === array_key_last($this->providers)) {
            return;
        }

        HookDispatcher::providerFallback(
            from: $this->providers[$failedIndex]->name(),
            to: $this->providers[$failedIndex + 1]->name(),
            status: $status,
        );
    }
}
