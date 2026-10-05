<?php

declare(strict_types=1);

namespace PhpClaw\Memory;

use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\Contracts\SearchableMemoryInterface;

/**
 * Keyword-overlap relevance for memory: a value scores one point for each distinct word of 3+ letters it shares with the query.
 */
final class TokenOverlapScorer
{
    private const MIN_KEYWORD_LENGTH = 3;

    /**
     * Search a driver: its own search() when it is searchable, otherwise rank every entry its all() returns.
     *
     * @param  MemoryInterface  $memory  Driver to query.
     * @param  string  $query  Free text to match.
     * @param  int  $limit  Maximum hits to return.
     * @param  string  $namespace  Memory namespace to search.
     * @return list<MemoryHit> Hits in descending score order, at most $limit.
     */
    public static function search(MemoryInterface $memory, string $query, int $limit, string $namespace): array
    {
        if ($memory instanceof SearchableMemoryInterface) {
            return $memory->search($query, $limit, $namespace);
        }

        return self::rank($memory->all($namespace), $query, $limit, $namespace);
    }

    /**
     * Rank entries by shared keywords with the query, dropping entries that share none.
     *
     * @param  array<array-key, mixed>  $entries  Key-value map, as all() returns it.
     * @param  string  $query  Free text to match.
     * @param  int  $limit  Maximum hits to return; 0 or less returns none.
     * @param  string  $namespace  Namespace the entries came from.
     * @return list<MemoryHit> Hits in descending score order, at most $limit.
     */
    public static function rank(array $entries, string $query, int $limit, string $namespace): array
    {
        if ($limit <= 0) {
            return [];
        }

        $queryWords = self::keywords($query);
        $scored = [];

        foreach ($entries as $key => $value) {
            $score = count(array_intersect($queryWords, self::keywords(self::text($value))));

            if ($score > 0) {
                $scored[$key] = ['score' => $score, 'value' => $value];
            }
        }

        arsort($scored);

        $hits = [];
        foreach (array_slice($scored, 0, $limit, true) as $key => $item) {
            $hits[] = new MemoryHit((string) $key, $item['value'], $namespace, (float) $item['score']);
        }

        return $hits;
    }

    /**
     * Render a stored value as text: strings as they are, anything else JSON-encoded.
     *
     * @param  mixed  $value  Stored value.
     * @return string
     */
    public static function text(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value);
    }

    /**
     * Lowercase, de-duplicated words of 3+ letters in the text.
     *
     * @param  string  $text  Text to split.
     * @return list<string>
     */
    public static function keywords(string $text): array
    {
        return array_values(array_unique(array_filter(
            str_word_count(strtolower($text), 1),
            static fn (string $word): bool => strlen($word) >= self::MIN_KEYWORD_LENGTH,
        )));
    }
}
