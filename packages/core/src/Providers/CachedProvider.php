<?php

declare(strict_types=1);

namespace PhpClaw\Providers;

use PhpClaw\Agent\Message;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Providers\Contracts\SupportsStructuredOutputInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * Decorator that caches send() responses in a PSR-16 store keyed by a fingerprint plus the request;
 * stream() always goes to the inner provider. Never share one cache store across tenants.
 */
final class CachedProvider implements ProviderInterface, SupportsStructuredOutputInterface
{
    private const KEY_PREFIX = 'phpclaw_';

    /**
     * Build a response-cache decorator around an inner provider.
     *
     * @param  ProviderInterface  $inner  Provider send() delegates to on a cache miss.
     * @param  CacheInterface  $cache  PSR-16 store used to persist responses.
     * @param  string  $fingerprint  Build-time signature (providers, system prompt, generation settings) joined into every cache key.
     * @param  int  $ttl  Seconds a cached response stays valid.
     * @return void
     */
    public function __construct(
        private readonly ProviderInterface $inner,
        private readonly CacheInterface $cache,
        private readonly string $fingerprint,
        private readonly int $ttl = 3600,
    ) {}

    /**
     * Return the cached response for this exact request when present, else call the inner provider and store its response.
     *
     * @param  Message[]  $messages  Full conversation history so far.
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas in provider-native format.
     * @return array{type: string, calls?: array<int,array<string,mixed>>, text?: string, input_tokens?: int|null, output_tokens?: int|null, cache_read_tokens?: int|null, cache_write_tokens?: int|null, thinking?: string|null}
     */
    public function send(array $messages, array $tools = []): array
    {
        $key = $this->buildKey($messages, $tools);
        $cached = $this->cache->get($key);

        if (is_array($cached)) {
            HookDispatcher::providerResponseCached($this->inner->name(), $this->inner->model(), $key);

            return array_merge($cached, [
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cache_read_tokens' => 0,
                'cache_write_tokens' => 0,
            ]);
        }

        $response = $this->inner->send($messages, $tools);
        $this->cache->set($key, $response, $this->ttl);

        return $response;
    }

    /**
     * Stream from the inner provider directly; streamed responses are never cached.
     *
     * @param  Message[]  $messages  Conversation history.
     * @param  callable(string):void  $onToken  Called with each text token as it arrives.
     * @return string Full assembled text from the inner provider.
     */
    public function stream(array $messages, callable $onToken): string
    {
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
     * Return a new CachedProvider around the inner provider's withResponseSchema() clone, joining the
     * schema's hash into the fingerprint so a structured call never shares an entry with a plain one.
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
            $this->cache,
            $this->fingerprint."\0".sha1((string) json_encode($schema)),
            $this->ttl,
        );
    }

    /**
     * Derive the cache key from the fingerprint plus the exact request; never uses the inner provider's name/model, which can change after a fallback.
     *
     * @param  Message[]  $messages  Full conversation history so far.
     * @param  array<int, array<string, mixed>>  $tools  Tool schemas in provider-native format.
     * @return string
     */
    private function buildKey(array $messages, array $tools): string
    {
        return self::KEY_PREFIX.sha1($this->fingerprint."\0".json_encode($messages)."\0".json_encode($tools));
    }
}
