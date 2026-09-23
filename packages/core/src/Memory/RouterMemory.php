<?php

declare(strict_types=1);

namespace PhpClaw\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Namespace-routed memory wrapper: routes every operation to a per-namespace driver, falling back to a default driver for unmapped namespaces.
 */
final class RouterMemory implements MemoryInterface
{
    /**
     * Create a new RouterMemory instance.
     *
     * @param  MemoryInterface  $default  Fallback driver for any namespace not present in $routes.
     * @param  array<string, MemoryInterface>  $routes  Map of namespace → driver. First match wins.
     */
    public function __construct(
        private readonly MemoryInterface $default,
        private readonly array $routes = [],
    ) {}

    /**
     * The driver chosen for the given namespace.
     *
     * @param  string  $namespace  Namespace to scope the operation.
     * @return MemoryInterface The result.
     */
    public function driverFor(string $namespace): MemoryInterface
    {
        return $this->routes[$namespace] ?? $this->default;
    }

    /**
     * Every routed namespace mapped to its driver (excludes the default).
     *
     * @return array The resulting list.
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * The fallback driver.
     *
     * @return MemoryInterface The result.
     */
    public function defaultDriver(): MemoryInterface
    {
        return $this->default;
    }

    /**
     * Read a value via the driver routed for the namespace.
     *
     * @param  string  $key  Storage key.
     * @param  string  $namespace  Namespace whose routed driver is used.
     * @return mixed Stored value, or null when absent.
     */
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->driverFor($namespace)->get($key, $namespace);
    }

    /**
     * Persist a value via the driver routed for the namespace.
     *
     * @param  string  $key  Storage key.
     * @param  mixed  $value  Value to store.
     * @param  string  $namespace  Namespace whose routed driver is used.
     * @param  int|null  $ttl  Time-to-live in seconds, or null for no expiry.
     * @return void
     */
    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        $this->driverFor($namespace)->set($key, $value, $namespace, $ttl);
    }

    /**
     * Delete a value via the driver routed for the namespace.
     *
     * @param  string  $key  Storage key.
     * @param  string  $namespace  Namespace whose routed driver is used.
     * @return void
     */
    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->driverFor($namespace)->forget($key, $namespace);
    }

    /**
     * Delete every value in the namespace via its routed driver.
     *
     * @param  string  $namespace  Namespace to clear.
     * @return void
     */
    public function flush(string $namespace = 'default'): void
    {
        $this->driverFor($namespace)->flush($namespace);
    }

    /**
     * Return every stored value in the namespace via its routed driver.
     *
     * @param  string  $namespace  Namespace to read.
     * @return array<string, mixed> Key-value map of stored entries.
     */
    public function all(string $namespace = 'default'): array
    {
        return $this->driverFor($namespace)->all($namespace);
    }

    /**
     * Report whether the routed driver holds a value for the key.
     *
     * @param  string  $key  Storage key.
     * @param  string  $namespace  Namespace whose routed driver is used.
     * @return bool True when the key exists.
     */
    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->driverFor($namespace)->has($key, $namespace);
    }
}
