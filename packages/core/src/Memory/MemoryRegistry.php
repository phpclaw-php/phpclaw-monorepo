<?php

declare(strict_types=1);

namespace PhpClaw\Memory;

use PhpClaw\Exceptions\MemoryException;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Static registry for memory drivers: 'file' and 'array' built-in drivers pre-registered.
 */
final class MemoryRegistry
{
    private const DRIVER_FILE = 'file';

    private const DRIVER_ARRAY = 'array';

    private static array $drivers = [];

    private static bool $booted = false;

    /**
     * Register a custom memory driver.
     *
     * @param  string  $name  Driver name (e.g. 'redis'). Case-insensitive.
     * @param  string|callable  $driver  Class name implementing MemoryInterface with a zero-arg constructor, or a callable factory returning a MemoryInterface instance.
     * @return void
     *
     * @throws MemoryException If $driver is a class string that does not exist or does not implement MemoryInterface.
     */
    public static function register(string $name, string|callable $driver): void
    {
        if (is_string($driver)) {
            if (! class_exists($driver)) {
                throw new MemoryException("Memory driver class not found: {$driver}");
            }

            if (! is_a($driver, MemoryInterface::class, true)) {
                throw new MemoryException(
                    "Memory driver class {$driver} must implement ".MemoryInterface::class
                );
            }
        }

        self::boot();

        self::$drivers[strtolower($name)] = $driver;
    }

    /**
     * Build and return the named memory driver.
     *
     * @param  string  $name  Registered driver name. Defaults to 'file'.
     * @return MemoryInterface
     *
     * @throws MemoryException If the name is not registered, or the factory returns a non-MemoryInterface.
     */
    public static function build(string $name = self::DRIVER_FILE): MemoryInterface
    {
        self::boot();

        $name = strtolower($name);

        if (! isset(self::$drivers[$name])) {
            throw new MemoryException(
                "No memory driver registered under name '{$name}'. Available: ".implode(', ', array_keys(self::$drivers))
            );
        }

        $entry = self::$drivers[$name];

        if (is_callable($entry)) {
            $instance = $entry();

            if (! ($instance instanceof MemoryInterface)) {
                throw new MemoryException(
                    "Factory for memory driver '{$name}' must return an instance of ".MemoryInterface::class
                );
            }

            return $instance;
        }

        return new $entry;
    }

    /**
     * Whether a driver is registered under the given name.
     *
     * @param  string  $name  Driver name to check.
     * @return bool
     */
    public static function has(string $name): bool
    {
        self::boot();

        return isset(self::$drivers[strtolower($name)]);
    }

    /**
     * Return all registered driver names.
     *
     * @return string[]
     */
    public static function drivers(): array
    {
        self::boot();

        return array_keys(self::$drivers);
    }

    /**
     * Reset to the default state (built-in drivers only).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$drivers = [];
        self::$booted = false;
        self::boot();
    }

    /**
     * Register built-in drivers the first time the registry is touched.
     *
     * @return void
     */
    private static function boot(): void
    {
        if (self::$booted) {
            return;
        }

        self::$drivers = [
            self::DRIVER_FILE => fn () => new FileMemory,
            self::DRIVER_ARRAY => fn () => new ArrayMemory,
        ];

        self::$booted = true;
    }
}
