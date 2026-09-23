<?php

declare(strict_types=1);

namespace PhpClaw\Mcp;

/**
 * Static registry for MCP resources.
 */
final class ResourceRegistry
{
    private static array $resources = [];

    /**
     * Register a resource.
     *
     * @param  string  $uri  Unique URI identifying this resource (e.g. "file:///logs/app.log").
     * @param  string  $name  Human-readable display name.
     * @param  string  $description  What the resource contains.
     * @param  callable  $reader  Zero-argument callable that returns the resource content as a string.
     * @param  string  $mimeType  MIME type of the resource content. Default: 'text/plain'.
     * @return void
     */
    public static function register(
        string $uri,
        string $name,
        string $description,
        callable $reader,
        string $mimeType = 'text/plain',
    ): void {
        self::$resources[$uri] = [
            'name' => $name,
            'description' => $description,
            'mimeType' => $mimeType,
            'reader' => $reader,
        ];
    }

    /**
     * Whether a resource with the given URI is registered.
     *
     * @param  string  $uri  Resource URI to look up.
     * @return bool True when the URI has been registered.
     */
    public static function has(string $uri): bool
    {
        return isset(self::$resources[$uri]);
    }

    /**
     * Call the reader for the given URI and return its content.
     *
     * @param  string  $uri  Resource URI to read.
     * @return string The content returned by the registered reader.
     *
     * @throws \RuntimeException If the URI is not registered.
     */
    public static function read(string $uri): string
    {
        if (! self::has($uri)) {
            throw new \RuntimeException("Resource not found: {$uri}");
        }

        return (string) (self::$resources[$uri]['reader'])();
    }

    /**
     * Return all resource schemas in MCP format (for resources/list).
     *
     * @return array<int, array<string, string>> One entry per registered resource.
     */
    public static function schemas(): array
    {
        $schemas = [];

        foreach (self::$resources as $uri => $r) {
            $schemas[] = [
                'uri' => $uri,
                'name' => $r['name'],
                'description' => $r['description'],
                'mimeType' => $r['mimeType'],
            ];
        }

        return $schemas;
    }

    /**
     * Total number of registered resources.
     *
     * @return int Number of resources currently registered.
     */
    public static function count(): int
    {
        return count(self::$resources);
    }

    /**
     * Clear all registered resources. Used in tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$resources = [];
    }
}
