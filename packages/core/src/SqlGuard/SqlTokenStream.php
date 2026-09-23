<?php

declare(strict_types=1);

namespace PhpClaw\SqlGuard;

/**
 * The ordered, upper-cased word tokens the guard lexed from outside every string literal, passed to SqlPolicyInterface implementations so adapter-specific rules inspect the already-parsed tokens instead of re-scanning the raw SQL.
 */
final class SqlTokenStream
{
    /**
     * Create a new SqlTokenStream instance.
     *
     * @param  string[]  $words  Upper-cased word tokens outside string literals, in order.
     * @return void
     */
    public function __construct(private readonly array $words) {}

    /**
     * All word tokens, upper-cased, in source order.
     *
     * @return string[]
     */
    public function words(): array
    {
        return $this->words;
    }

    /**
     * The first word token, or null when the query has none.
     *
     * @return ?string
     */
    public function firstWord(): ?string
    {
        return $this->words[0] ?? null;
    }

    /**
     * Whether the given upper-cased word appears anywhere in the token stream.
     *
     * @param  string  $word  Upper-cased word to look for.
     * @return bool
     */
    public function hasWord(string $word): bool
    {
        return in_array($word, $this->words, true);
    }

    /**
     * Whether two upper-cased words appear consecutively in the token stream.
     *
     * @param  string  $first  Upper-cased first word.
     * @param  string  $second  Upper-cased second word.
     * @return bool
     */
    public function hasSequence(string $first, string $second): bool
    {
        $count = count($this->words);

        for ($i = 0; $i + 1 < $count; $i++) {
            if ($this->words[$i] === $first && $this->words[$i + 1] === $second) {
                return true;
            }
        }

        return false;
    }
}
