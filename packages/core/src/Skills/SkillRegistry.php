<?php

declare(strict_types=1);

namespace PhpClaw\Skills;

use PhpClaw\Hooks\Dispatchers\SkillEventDispatcher;
use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Global registry for skills, keyed by name.
 */
final class SkillRegistry
{
    public const DEFAULT_MATCH_LIMIT = 3;

    public const STOPWORDS = [
        'the', 'and', 'with', 'for', 'this', 'that', 'from', 'your', 'you', 'are', 'was', 'has',
        'have', 'had', 'will', 'can', 'its', 'their', 'them', 'then', 'than', 'into', 'over',
        'more', 'most', 'any', 'all', 'but', 'not', 'out', 'use', 'using', 'when', 'what', 'which',
        'who', 'how', 'why', 'here', 'there', 'each', 'also', 'only', 'some', 'such', 'these',
        'those', 'they', 'been', 'being', 'does', 'did', 'get', 'got', 'let', 'make', 'made',
        'like', 'just', 'one', 'two', 'per', 'via', 'off', 'own', 'new', 'now',
        'every', 'everything', 'anything', 'something', 'nothing', 'both', 'many', 'much', 'few',
        'other', 'another', 'same',
    ];

    private static array $skills = [];

    /**
     * Register a skill, keyed by its name.
     *
     * @param  SkillInterface  $skill  Skill implementation to register.
     * @return void
     */
    public static function register(SkillInterface $skill): void
    {
        self::$skills[$skill->name()] = $skill;
        SkillEventDispatcher::registered($skill->name(), $skill::class);
        SkillEventDispatcher::loaded($skill->name(), $skill::class);
    }

    /**
     * Remove all registered skills. Required between tests.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$skills = [];
    }

    /**
     * Whether a skill is registered under the given name.
     *
     * @param  string  $name  Skill name.
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::$skills[$name]);
    }

    /**
     * Return all registered skills as a positional array.
     *
     * @return SkillInterface[]
     */
    public static function all(): array
    {
        return array_values(self::$skills);
    }

    /**
     * Return all registered skill names.
     *
     * @return string[]
     */
    public static function names(): array
    {
        return array_keys(self::$skills);
    }

    /**
     * Number of registered skills.
     *
     * @return int
     */
    public static function count(): int
    {
        return count(self::$skills);
    }
}
