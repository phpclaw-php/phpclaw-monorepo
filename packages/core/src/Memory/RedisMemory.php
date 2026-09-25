<?php

declare(strict_types=1);

namespace PhpClaw\Memory;

use PhpClaw\AutoDiscovery\Attributes\Memory;
use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Hooks\HookDispatcher;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Support\Log;

/**
 * phpClaw memory driver backed by Redis (requires ext-redis).
 */
#[Memory(driver: 'redis', label: 'Redis', since: '1.0.0')]
final class RedisMemory implements MemoryInterface
{
    private const DRIVER_NAME = 'redis';

    private const DEFAULT_NAMESPACE = 'default';

    private const DEFAULT_TTL = 86400;

    private const DEFAULT_PREFIX = 'phpclaw:';

    private const ENV_KEY_URL = 'REDIS_URL';

    private const DEFAULT_URL = 'redis://127.0.0.1:6379';

    private const DEFAULT_PORT = 6379;

    private const DEFAULT_CONNECT_TIMEOUT = 1.0;

    private readonly \Redis $redis;

    private readonly string $prefix;

    private readonly int $defaultTtl;

    /**
     * Construct a RedisMemory driver, either using a preconfigured \Redis instance or by opening a fresh connection.
     *
     * @param  \Redis|null  $redis  Optional preconfigured connection (useful in tests).
     * @param  string  $url  Connection URL. Read from REDIS_URL env if empty.
     * @param  int  $defaultTtl  Default TTL in seconds when set() ttl is null. 0 = never expires.
     * @param  string  $prefix  Key prefix to isolate phpClaw keys from other applications.
     * @return void
     *
     * @throws MemoryException When ext-redis is missing and no \Redis instance is injected, or when the connect URL is invalid / unreachable.
     */
    public function __construct(
        ?\Redis $redis = null,
        string $url = '',
        int $defaultTtl = self::DEFAULT_TTL,
        string $prefix = self::DEFAULT_PREFIX,
    ) {
        $this->prefix = $prefix;
        $this->defaultTtl = max(0, $defaultTtl);

        if ($redis !== null) {
            $this->redis = $redis;

            return;
        }

        if (! extension_loaded('redis')) {
            throw new MemoryException(
                'RedisMemory requires the ext-redis extension. Install it or inject a \\Redis instance.'
            );
        }

        $resolved = $url !== ''
            ? $url
            : (string) ($_ENV[self::ENV_KEY_URL] ?? getenv(self::ENV_KEY_URL) ?: self::DEFAULT_URL);

        $this->redis = $this->connect($resolved);
    }

    /**
     * Read a value from Redis under the given namespace; returns null on miss or corrupt JSON.
     *
     * @param  string  $key  Lookup key.
     * @param  string  $namespace  Logical namespace; combined with key to form the Redis key.
     * @return mixed Decoded value on hit, null on miss or invalid JSON.
     */
    public function get(string $key, string $namespace = self::DEFAULT_NAMESPACE): mixed
    {
        NamespaceValidator::validate($namespace);
        $raw = $this->redis->get($this->key($namespace, $key));

        if ($raw === false) {
            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, hit: false);

            return null;
        }

        try {
            $value = $this->decode((string) $raw);
        } catch (\JsonException) {
            HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, hit: false);

            return null;
        }

        HookDispatcher::memoryRead($key, $namespace, self::DRIVER_NAME, hit: true);

        return $value;
    }

    /**
     * Write a JSON-encoded value to Redis; uses SETEX when an effective TTL is positive, plain SET otherwise.
     *
     * @param  string  $key  Lookup key.
     * @param  mixed  $value  JSON-encodable value to persist.
     * @param  string  $namespace  Logical namespace; combined with key to form the Redis key.
     * @param  int|null  $ttl  Per-call TTL in seconds; null uses the constructor defaultTtl. 0 = never expires.
     * @return void
     *
     * @throws \JsonException When $value cannot be JSON-encoded.
     */
    public function set(
        string $key,
        mixed $value,
        string $namespace = self::DEFAULT_NAMESPACE,
        ?int $ttl = null,
    ): void {
        NamespaceValidator::validate($namespace);
        $serialised = json_encode($value, JSON_THROW_ON_ERROR);
        $expiry = $ttl ?? $this->defaultTtl;
        $redisKey = $this->key($namespace, $key);

        if ($expiry > 0) {
            $this->redis->setex($redisKey, $expiry, $serialised);
        } else {
            $this->redis->set($redisKey, $serialised);
        }

        HookDispatcher::memoryWrite($key, $namespace, self::DRIVER_NAME, $expiry > 0 ? $expiry : null);
    }

    /**
     * Delete a single key in the given namespace and fire the memory.forget hook.
     *
     * @param  string  $key  Lookup key to delete.
     * @param  string  $namespace  Logical namespace; combined with key to form the Redis key.
     * @return void
     */
    public function forget(string $key, string $namespace = self::DEFAULT_NAMESPACE): void
    {
        NamespaceValidator::validate($namespace);
        $this->redis->del($this->key($namespace, $key));

        HookDispatcher::memoryForget($key, $namespace, self::DRIVER_NAME);
    }

    /**
     * Delete every key in the given namespace.
     *
     * @param  string  $namespace  Namespace whose keys should be wiped.
     * @return void
     */
    public function flush(string $namespace = self::DEFAULT_NAMESPACE): void
    {
        NamespaceValidator::validate($namespace);
        $keys = $this->redis->keys($this->namespacePattern($namespace));

        if (is_array($keys) && ! empty($keys)) {
            $this->redis->del($keys);
        }
    }

    /**
     * Return every key/value pair in the given namespace, decoded from JSON.
     *
     * @param  string  $namespace  Namespace to enumerate.
     * @return array<string, mixed> Map of short-key → decoded value.
     */
    public function all(string $namespace = self::DEFAULT_NAMESPACE): array
    {
        NamespaceValidator::validate($namespace);
        $keys = $this->redis->keys($this->namespacePattern($namespace));

        if (! is_array($keys) || empty($keys)) {
            return [];
        }

        $result = [];
        $strip = $this->prefix.$namespace.':';

        foreach ($keys as $fullKey) {
            $shortKey = str_starts_with((string) $fullKey, $strip)
                ? substr((string) $fullKey, strlen($strip))
                : (string) $fullKey;

            $raw = $this->redis->get((string) $fullKey);

            if ($raw === false) {
                continue;
            }

            try {
                $value = $this->decode((string) $raw);
            } catch (\JsonException) {
                continue;
            }

            $result[$shortKey] = $value;
        }

        return $result;
    }

    /**
     * Whether the key exists in Redis under the given namespace.
     *
     * @param  string  $key  Lookup key.
     * @param  string  $namespace  Logical namespace; combined with key to form the Redis key.
     * @return bool True when Redis reports the key exists (count > 0).
     */
    public function has(string $key, string $namespace = self::DEFAULT_NAMESPACE): bool
    {
        NamespaceValidator::validate($namespace);

        return (int) $this->redis->exists($this->key($namespace, $key)) > 0;
    }

    /**
     * Return the key prefix used to isolate phpClaw keys from other apps sharing this Redis instance.
     *
     * @return string The prefix passed to the constructor (default 'phpclaw:').
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Return the default TTL (seconds) applied when set() is called without a per-call ttl.
     *
     * @return int Default TTL in seconds; 0 means keys never expire.
     */
    public function defaultTtl(): int
    {
        return $this->defaultTtl;
    }

    /**
     * Return remaining TTL (seconds) for a key, -1 if no TTL, -2 if key doesn't exist.
     *
     * @param  string  $key  Lookup key.
     * @param  string  $namespace  Logical namespace; combined with key to form the Redis key.
     * @return int Remaining seconds, -1 (no TTL), or -2 (missing key).
     */
    public function ttl(string $key, string $namespace = self::DEFAULT_NAMESPACE): int
    {
        return (int) $this->redis->ttl($this->key($namespace, $key));
    }

    /**
     * Decode a JSON-encoded Redis value into a mixed PHP value.
     *
     * @param  string  $raw  JSON string returned by Redis.
     * @return mixed
     *
     * @throws \JsonException When the string is not valid JSON.
     */
    private function decode(string $raw): mixed
    {
        return json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Compose the prefixed Redis key for a (namespace, key) pair.
     *
     * @param  string  $namespace  Logical namespace.
     * @param  string  $key  Lookup key within the namespace.
     * @return string Concatenated "{prefix}{namespace}:{key}" string.
     */
    private function key(string $namespace, string $key): string
    {
        return $this->prefix.$namespace.':'.$key;
    }

    /**
     * Build the glob pattern used by Redis KEYS to enumerate a namespace.
     *
     * @param  string  $namespace  Logical namespace.
     * @return string "{prefix}{namespace}:*" glob pattern.
     */
    private function namespacePattern(string $namespace): string
    {
        return $this->prefix.$namespace.':*';
    }

    /**
     * Parse a Redis URL and open a connection.
     *
     * @param  string  $url  Redis URL in the form `redis://[:password@]host[:port][/db]`.
     * @return \Redis Open connection with auth + database selection applied as parsed from the URL.
     *
     * @throws MemoryException When the URL is invalid or the connection cannot be opened.
     */
    private function connect(string $url): \Redis
    {
        $parsed = parse_url($url);

        if ($parsed === false || ! isset($parsed['host'])) {
            throw new MemoryException("RedisMemory: invalid REDIS_URL '{$url}'.");
        }

        $redis = new \Redis;

        try {
            $connected = $redis->connect(
                (string) $parsed['host'],
                (int) ($parsed['port'] ?? self::DEFAULT_PORT),
                self::DEFAULT_CONNECT_TIMEOUT,
            );
        } catch (\Throwable $exception) {
            Log::error('[phpClaw] RedisMemory: connect failed: '.$exception->getMessage());
            throw new MemoryException("RedisMemory: could not connect to host '{$parsed['host']}'.");
        }

        if (! $connected) {
            throw new MemoryException("RedisMemory: could not connect to host '{$parsed['host']}'.");
        }

        if (isset($parsed['pass']) && $parsed['pass'] !== '') {
            $redis->auth((string) $parsed['pass']);
        }

        if (isset($parsed['path']) && preg_match('#^/(\d+)$#', $parsed['path'], $matches) === 1) {
            $redis->select((int) $matches[1]);
        }

        return $redis;
    }
}
