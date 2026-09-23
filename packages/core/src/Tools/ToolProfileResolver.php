<?php

declare(strict_types=1);

namespace PhpClaw\Tools;

use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * Resolves the tool profile based on provider and model size.
 */
final class ToolProfileResolver
{
    public const PROFILE_MINIMAL = 'minimal';

    public const PROFILE_STANDARD = 'standard';

    public const PROFILE_FULL = 'full';

    private const UNLIMITED = 0;

    private const PROFILES = [
        self::PROFILE_MINIMAL => 5,
        self::PROFILE_STANDARD => 8,
        self::PROFILE_FULL => self::UNLIMITED,
    ];

    private const MEDIUM_PATTERNS = [
        '30b', '32b', '34b', '35b', '65b', '70b', '72b',
    ];

    private const LOCAL_PROVIDERS = ['ollama', 'groq'];

    /**
     * Resolve which tool profile to use based on provider and model size.
     *
     * @param  string  $provider  Provider slug (e.g. 'ollama', 'openai').
     * @param  string  $model  Model identifier.
     * @return string One of PROFILE_FULL / PROFILE_STANDARD / PROFILE_MINIMAL.
     */
    public static function resolve(string $provider, string $model): string
    {
        if (! in_array($provider, self::LOCAL_PROVIDERS, strict: true)) {
            return self::PROFILE_FULL;
        }

        return self::isMediumModel($model)
            ? self::PROFILE_STANDARD
            : self::PROFILE_MINIMAL;
    }

    /**
     * Remove every denied tool, expanding any group reference in the deny list.
     *
     * @param  ToolInterface[]  $tools  All available tools in priority order.
     * @param  string[]  $deny  Tool names or group references to block.
     * @param  array<string, string[]>  $groups  Group definitions (e.g. ['group:content' => ['wp_query', ...]]).
     * @return ToolInterface[]
     */
    public static function filter(
        array $tools,
        array $deny = [],
        array $groups = [],
    ): array {
        $tools = self::removeDenied($tools, $deny, $groups);

        return array_values($tools);
    }

    /**
     * Max tool count for a given profile (0 = no limit).
     *
     * @param  string  $profile  Profile name.
     * @return int
     */
    public static function maxTools(string $profile): int
    {
        return self::PROFILES[$profile] ?? self::UNLIMITED;
    }

    /**
     * Remove every tool whose name resolves to a deny-listed entry.
     *
     * @param  ToolInterface[]  $tools  Tools to filter.
     * @param  string[]  $deny  Names or group references to block.
     * @param  array<string, string[]>  $groups  Group definitions.
     * @return ToolInterface[]
     */
    private static function removeDenied(array $tools, array $deny, array $groups): array
    {
        if (empty($deny)) {
            return $tools;
        }

        $denyNames = self::resolveNames($deny, $groups);

        return array_filter(
            $tools,
            static fn (ToolInterface $t): bool => ! in_array($t->name(), $denyNames, strict: true),
        );
    }

    /**
     * Resolve a mixed list of tool names and group references into flat tool names.
     *
     * @param  string[]  $entries  Mixed list of tool names or group references.
     * @param  array<string, string[]>  $groups  Group definitions.
     * @return string[]
     */
    public static function resolveNames(array $entries, array $groups): array
    {
        $names = [];

        foreach ($entries as $entry) {
            if (isset($groups[$entry])) {
                foreach ($groups[$entry] as $name) {
                    $names[] = $name;
                }

                continue;
            }

            $names[] = $entry;
        }

        return array_values(array_unique($names));
    }

    /**
     * Return true when the model identifier matches any medium-size pattern.
     *
     * @param  string  $model  Model identifier.
     * @return bool
     */
    private static function isMediumModel(string $model): bool
    {
        $modelLower = strtolower($model);

        foreach (self::MEDIUM_PATTERNS as $pattern) {
            if (str_contains($modelLower, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
