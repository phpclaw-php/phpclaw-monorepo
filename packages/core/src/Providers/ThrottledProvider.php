<?php

declare(strict_types=1);

namespace PhpClaw\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Exceptions\ProviderException;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Contracts\SupportsStructuredOutputInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Token-bucket throttle taking one token per send() or stream(), shared with withResponseSchema() clones and,
 * when a store is given, with every ThrottledProvider on that store; RateLimitGuard is the inbound guard.
 */
final class ThrottledProvider implements ProviderInterface, SupportsStructuredOutputInterface
{
    private const MS_PER_MINUTE = 60_000;

    private const HTTP_TOO_MANY_REQUESTS = 429;

    private const STORE_TTL_SECONDS = 120;

    private const STORE_KEY = 'phpclaw_rate_limit_bucket';

    private readonly \Closure $now;

    private readonly \Closure $sleep;

    private readonly \stdClass $bucket;

    private readonly ?CacheInterface $store;

    /**
     * Build a token-bucket throttle around an inner provider.
     *
     * @param  ProviderInterface  $inner  Provider every send()/stream() call delegates to once a token is available.
     * @param  int  $requestsPerMinute  Bucket capacity and refill rate; must be greater than 0.
     * @param  int  $maxWaitMs  Longest wait for a token before giving up.
     * @param  \Closure|null  $now  Returns the current time in milliseconds; defaults to microtime(true) * 1000 when $store is given (a wall clock comparable across processes), otherwise hrtime(true) / 1e6.
     * @param  \Closure|null  $sleep  Sleeps for the given number of milliseconds; defaults to usleep.
     * @param  \stdClass|null  $bucket  Shared mutable bucket state ({tokens, lastRefillMs}); defaults to a fresh, full bucket. Passed by withResponseSchema() so a clone shares the original bucket.
     * @param  CacheInterface|null  $store  Optional PSR-16 store; when given, the bucket is loaded from and saved to one fixed key before and after each token computation, with a 120-second TTL, so the limit holds across every ThrottledProvider given the same store. Shared counts are approximate under concurrent requests, because the read, update, write is not atomic.
     * @return void
     *
     * @throws AdapterException When requestsPerMinute is not greater than 0.
     */
    public function __construct(
        private readonly ProviderInterface $inner,
        private readonly int $requestsPerMinute,
        private readonly int $maxWaitMs = 30_000,
        ?\Closure $now = null,
        ?\Closure $sleep = null,
        ?\stdClass $bucket = null,
        ?CacheInterface $store = null,
    ) {
        if ($this->requestsPerMinute <= 0) {
            throw new AdapterException('ThrottledProvider requires requestsPerMinute to be greater than 0.');
        }

        $this->store = $store;
        $this->now = $now ?? ($store !== null
            ? static fn (): float => microtime(true) * 1000
            : static fn (): float => hrtime(true) / 1e6);
        $this->sleep = $sleep ?? static function (float $milliseconds): void {
            usleep((int) ($milliseconds * 1000));
        };
        $this->bucket = $bucket ?? self::freshBucket((float) $this->requestsPerMinute, ($this->now)());
    }

    /**
     * Build a fresh, full token bucket.
     *
     * @param  float  $tokens  Starting token count, equal to the bucket capacity.
     * @param  float  $lastRefillMs  Current time in milliseconds, used as the initial refill anchor.
     * @return \stdClass
     */
    private static function freshBucket(float $tokens, float $lastRefillMs): \stdClass
    {
        $bucket = new \stdClass;
        $bucket->tokens = $tokens;
        $bucket->lastRefillMs = $lastRefillMs;

        return $bucket;
    }

    /**
     * Take a token from the bucket, then delegate to the inner provider.
     *
     * @param  Message[]  $messages  Full conversation history so far.
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas in provider-native format.
     * @return array{type: string, calls?: array<int,array<string,mixed>>, text?: string, input_tokens?: int|null, output_tokens?: int|null, cache_read_tokens?: int|null, cache_write_tokens?: int|null, thinking?: string|null}
     *
     * @throws ProviderException When the wait for a token would exceed maxWaitMs.
     */
    public function send(array $messages, array $tools = []): array
    {
        $this->acquireToken();

        return $this->inner->send($messages, $tools);
    }

    /**
     * Take a token from the bucket, then delegate to the inner provider.
     *
     * @param  Message[]  $messages  Conversation history.
     * @param  callable(string):void  $onToken  Called with each text token as it arrives.
     * @return string Full assembled text from the inner provider.
     *
     * @throws ProviderException When the wait for a token would exceed maxWaitMs.
     */
    public function stream(array $messages, callable $onToken): string
    {
        $this->acquireToken();

        return $this->inner->stream($messages, $onToken);
    }

    /**
     * Provider identifier of the inner provider.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->inner->name();
    }

    /**
     * Default model of the inner provider.
     *
     * @return string
     */
    public function model(): string
    {
        return $this->inner->model();
    }

    /**
     * Whether the inner provider implements SupportsStructuredOutputInterface and reports true.
     *
     * @return bool
     */
    public function supportsResponseSchema(): bool
    {
        return $this->inner instanceof SupportsStructuredOutputInterface && $this->inner->supportsResponseSchema();
    }

    /**
     * Return a new ThrottledProvider around the inner provider's withResponseSchema() clone, sharing this
     * instance's bucket so a plain call and a structured call draw from one token count.
     *
     * @param  array<string, mixed>  $schema  JSON Schema the inner provider should enforce natively.
     * @return static
     */
    public function withResponseSchema(array $schema): static
    {
        $inner = $this->inner instanceof SupportsStructuredOutputInterface
            ? $this->inner->withResponseSchema($schema)
            : $this->inner;

        return new self(
            $inner,
            $this->requestsPerMinute,
            $this->maxWaitMs,
            $this->now,
            $this->sleep,
            $this->bucket,
            $this->store,
        );
    }

    /**
     * Refill the bucket for elapsed time, take one token, sleeping first when the bucket is empty. When a store is
     * configured, the bucket is loaded from it before this computation and saved back after.
     *
     * @return void
     *
     * @throws ProviderException When the computed wait exceeds maxWaitMs.
     */
    private function acquireToken(): void
    {
        $this->loadBucketFromStore();

        $refillPerMs = $this->requestsPerMinute / self::MS_PER_MINUTE;
        $now = ($this->now)();
        $this->bucket->tokens = min((float) $this->requestsPerMinute, $this->bucket->tokens + ($now - $this->bucket->lastRefillMs) * $refillPerMs);
        $this->bucket->lastRefillMs = $now;

        if ($this->bucket->tokens >= 1.0) {
            $this->bucket->tokens -= 1.0;
            $this->saveBucketToStore();

            return;
        }

        $waitMs = (1.0 - $this->bucket->tokens) / $refillPerMs;

        if ($waitMs > $this->maxWaitMs) {
            $this->saveBucketToStore();

            throw new ProviderException(
                sprintf('rate limit wait exceeded: waiting %.0fms for a token would exceed the %dms maximum wait', $waitMs, $this->maxWaitMs),
                self::HTTP_TOO_MANY_REQUESTS,
            );
        }

        ($this->sleep)($waitMs);
        $this->bucket->tokens = 0.0;
        $this->bucket->lastRefillMs = $now + $waitMs;
        $this->saveBucketToStore();
    }

    /**
     * Overwrite the in-memory bucket with the state held in the store, when a valid entry is present.
     *
     * @return void
     */
    private function loadBucketFromStore(): void
    {
        if ($this->store === null) {
            return;
        }

        $state = $this->store->get(self::STORE_KEY);

        if (! is_array($state) || ! isset($state['tokens'], $state['lastRefillMs'])) {
            return;
        }

        $this->bucket->tokens = (float) $state['tokens'];
        $this->bucket->lastRefillMs = (float) $state['lastRefillMs'];
    }

    /**
     * Persist the current in-memory bucket to the store, when one is configured.
     *
     * @return void
     */
    private function saveBucketToStore(): void
    {
        if ($this->store === null) {
            return;
        }

        $this->store->set(
            self::STORE_KEY,
            ['tokens' => $this->bucket->tokens, 'lastRefillMs' => $this->bucket->lastRefillMs],
            self::STORE_TTL_SECONDS,
        );
    }
}
