<?php

declare(strict_types=1);

namespace PhpClaw\Mcp;

/**
 * Static registry for MCP prompt templates.
 */
final class PromptRegistry
{
    private static array $prompts = [];

    /**
     * Register a prompt template.
     *
     * @param  string  $name  Unique prompt name (e.g. 'summarise-logs').
     * @param  string  $description  What this prompt does.
     * @param  array<int, array<string, mixed>>  $arguments  Argument definitions.
     * @param  callable  $renderer  fn(array $arguments): array, returns the MCP messages array.
     * @return void
     */
    public static function register(
        string $name,
        string $description,
        array $arguments,
        callable $renderer,
    ): void {
        self::$prompts[$name] = [
            'description' => $description,
            'arguments' => $arguments,
            'renderer' => $renderer,
        ];
    }

    /**
     * Whether a prompt with the given name is registered.
     *
     * @param  string  $name  Prompt name to look up.
     * @return bool True when the name has been registered.
     */
    public static function has(string $name): bool
    {
        return isset(self::$prompts[$name]);
    }

    /**
     * Render a prompt with the supplied arguments.
     *
     * @param  string  $name  Prompt name to render.
     * @param  array<string, mixed>  $arguments  Named argument values.
     * @return array<string, mixed> MCP prompts/get response: {description, messages}.
     *
     * @throws \RuntimeException If the prompt name is not registered.
     */
    public static function render(string $name, array $arguments): array
    {
        if (! self::has($name)) {
            throw new \RuntimeException("Prompt not found: {$name}");
        }

        $prompt = self::$prompts[$name];
        $messages = (array) ($prompt['renderer'])($arguments);

        return [
            'description' => $prompt['description'],
            'messages' => $messages,
        ];
    }

    /**
     * Return all prompt schemas in MCP format (for prompts/list).
     *
     * @return array<int, array<string, mixed>> One entry per registered prompt.
     */
    public static function schemas(): array
    {
        $schemas = [];

        foreach (self::$prompts as $name => $p) {
            $schemas[] = [
                'name' => $name,
                'description' => $p['description'],
                'arguments' => $p['arguments'],
            ];
        }

        return $schemas;
    }

    /**
     * Total number of registered prompts.
     *
     * @return int Number of prompts currently registered.
     */
    public static function count(): int
    {
        return count(self::$prompts);
    }

    /**
     * Clear all registered prompts. Used in tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$prompts = [];
    }
}
