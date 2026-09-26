<?php

declare(strict_types=1);

namespace PhpClaw\Skills;

use PhpClaw\Hooks\Dispatchers\SkillEventDispatcher;
use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Global registry for skills: keyword-matches and returns the top-N per turn.
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

    private const MESSAGE_WORD_MIN_LENGTH = 3;

    private const CORPUS_WORD_MIN_LENGTH = 4;

    private const TAG_WORD_MIN_LENGTH = self::MESSAGE_WORD_MIN_LENGTH;

    private const MIN_MATCH_SCORE = 1;

    private const WORD_LIST_FORMAT = 1;

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

    /**
     * Return the top-$limit skills most relevant to $message by keyword overlap.
     *
     * @param  string  $message  User message to match against.
     * @param  int  $limit  Maximum number of skills to return.
     * @return SkillInterface[]
     */
    public static function match(string $message, int $limit = self::DEFAULT_MATCH_LIMIT): array
    {
        if (empty(self::$skills)) {
            return [];
        }

        $messageWords = self::tokenise($message, self::MESSAGE_WORD_MIN_LENGTH);
        $scored = [];

        foreach (self::$skills as $name => $skill) {
            $score = self::scoreSkill($skill, $messageWords);

            if ($score > 0) {
                $scored[$name] = $score;
            }
        }

        if (empty($scored)) {
            return [];
        }

        uksort($scored, static function (string $a, string $b) use ($scored): int {
            return $scored[$b] <=> $scored[$a] ?: strcmp($a, $b);
        });
        $top = array_slice($scored, 0, $limit, preserve_keys: true);

        return array_map(static fn (string $name): SkillInterface => self::$skills[$name], array_keys($top));
    }

    /**
     * Score a single skill against the message words, count of overlapping tokens.
     *
     * @param  SkillInterface  $skill  Skill being scored.
     * @param  string[]  $messageWords  Tokenised user message words.
     * @return int
     */
    private static function scoreSkill(SkillInterface $skill, array $messageWords): int
    {
        $overlap = array_diff(
            array_intersect($messageWords, self::buildCorpus($skill)),
            self::STOPWORDS,
        );

        return count($overlap) >= self::MIN_MATCH_SCORE ? count($overlap) : 0;
    }

    /**
     * Build the matchable corpus for a skill: description tokens union tokenised tag words. The
     * name is an identifier, not evidence of topic, so it is never tokenised into the corpus.
     *
     * @param  SkillInterface  $skill  Skill to extract corpus from.
     * @return string[]
     */
    private static function buildCorpus(SkillInterface $skill): array
    {
        $descriptionWords = self::tokenise(
            $skill->description(),
            self::CORPUS_WORD_MIN_LENGTH,
        );

        $tagWords = self::tokenise(
            implode(' ', $skill->tags()),
            self::TAG_WORD_MIN_LENGTH,
        );

        return array_unique(array_merge($descriptionWords, $tagWords));
    }

    /**
     * Lowercase + hyphen/underscore-split + word-split + length-filter + dedupe a string into a list of tokens.
     *
     * @param  string  $text  Source text to tokenise.
     * @param  int  $minLength  Discard tokens shorter than this length.
     * @return string[]
     */
    private static function tokenise(string $text, int $minLength): array
    {
        $normalised = str_replace(['-', '_'], ' ', strtolower($text));

        return array_values(array_unique(array_filter(
            str_word_count($normalised, self::WORD_LIST_FORMAT),
            static fn (string $word): bool => strlen($word) >= $minLength,
        )));
    }
}
