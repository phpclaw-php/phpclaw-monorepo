<?php

declare(strict_types=1);

namespace PhpClaw\Memory;

/**
 * One memory entry returned by a search, with the driver-defined relevance score it ranked by.
 */
final class MemoryHit
{
    /**
     * Build a search hit.
     *
     * @param  string  $key  Storage key of the entry.
     * @param  mixed  $value  Stored value, as the driver returns it from get().
     * @param  string  $namespace  Namespace the entry was found in.
     * @param  float  $score  Driver-defined relevance; core drivers use the count of shared keywords.
     * @param  \DateTimeImmutable|null  $storedAt  When the entry was written, when the driver records it.
     * @return void
     */
    public function __construct(
        public readonly string $key,
        public readonly mixed $value,
        public readonly string $namespace,
        public readonly float $score,
        public readonly ?\DateTimeImmutable $storedAt = null,
    ) {}
}
