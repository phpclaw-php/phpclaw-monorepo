<?php

declare(strict_types=1);

namespace PhpClaw\Providers;

use PhpClaw\ClawConfig;
use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Http\RawHttpClient;
use PhpClaw\Http\StreamParser;
use PhpClaw\Providers\Contracts\ProviderInterface;

/**
 * Static registry for custom LLM providers, taking priority over built-in auto-detection.
 */
final class ProviderRegistry
{
    private static array $providers = [];

    /**
     * Register a custom provider under a case-insensitive name.
     *
     * @param  string  $name  e.g. 'myai'. Stored lowercased.
     * @param  string|callable  $provider  Class name (must implement ProviderInterface),
     * @return void
     *
     * @throws AdapterException When a class-string registration does not implement ProviderInterface.
     */
    public static function register(string $name, string|callable $provider): void
    {
        if (is_string($provider)) {
            self::assertImplementsProvider($provider);
        }

        self::$providers[self::normalise($name)] = $provider;
    }

    /**
     * Whether a provider is registered under the given name (case-insensitive).
     *
     * @param  string  $name  Provider name to look up.
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$providers[self::normalise($name)]);
    }

    /**
     * Return all registered provider names (already lowercased).
     *
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::$providers);
    }

    /**
     * Number of registered providers.
     *
     * @return int
     */
    public static function count(): int
    {
        return count(self::$providers);
    }

    /**
     * Build and return the registered provider for $name.
     *
     * @param  string  $name  Provider name (case-insensitive).
     * @param  ClawConfig  $config  Immutable config passed to factories or used to instantiate class strings.
     * @return ProviderInterface
     *
     * @throws AdapterException When the name is unknown, or a factory does not return ProviderInterface.
     */
    public static function build(string $name, ClawConfig $config): ProviderInterface
    {
        $key = self::normalise($name);

        if (! isset(self::$providers[$key])) {
            throw new AdapterException("No provider registered under name '{$key}'.");
        }

        $entry = self::$providers[$key];

        return is_callable($entry)
            ? self::buildFromFactory($entry, $key, $config)
            : self::buildFromClass($entry, $config);
    }

    /**
     * Remove all registered providers. Required between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$providers = [];
    }

    /**
     * Invoke a factory callable and verify it produced a ProviderInterface.
     *
     * @param  callable  $factory  Factory callable returning a ProviderInterface.
     * @param  string  $name  Registered name (for error messages).
     * @param  ClawConfig  $config  Config passed to the factory.
     * @return ProviderInterface
     *
     * @throws AdapterException When the factory does not return a ProviderInterface instance.
     */
    private static function buildFromFactory(callable $factory, string $name, ClawConfig $config): ProviderInterface
    {
        $instance = $factory($config);

        if (! ($instance instanceof ProviderInterface)) {
            throw new AdapterException(
                "Factory for provider '{$name}' must return an instance of ".ProviderInterface::class
            );
        }

        return $instance;
    }

    /**
     * Instantiate a provider class via its standard 4-argument constructor.
     *
     * @param  class-string<ProviderInterface>  $class  Provider class name.
     * @param  ClawConfig  $config  Immutable config sourcing apiKey / model.
     * @return ProviderInterface
     */
    private static function buildFromClass(string $class, ClawConfig $config): ProviderInterface
    {
        return new $class(
            $config->apiKey,
            new RawHttpClient,
            new StreamParser,
            $config->model,
        );
    }

    /**
     * Reject class-string registrations that do not implement ProviderInterface.
     *
     * @param  string  $class  Fully-qualified class name.
     * @return void
     *
     * @throws AdapterException When the class is missing or does not implement ProviderInterface.
     */
    private static function assertImplementsProvider(string $class): void
    {
        if (! class_exists($class)) {
            throw new AdapterException("Provider class not found: {$class}");
        }

        if (! is_a($class, ProviderInterface::class, true)) {
            throw new AdapterException(
                "Provider class {$class} must implement ".ProviderInterface::class
            );
        }
    }

    /**
     * Lower-case the registry key so lookups are case-insensitive.
     *
     * @param  string  $name  Raw provider name.
     * @return string
     */
    private static function normalise(string $name): string
    {
        return strtolower($name);
    }
}
