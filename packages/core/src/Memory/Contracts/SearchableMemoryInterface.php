<?php

declare(strict_types=1);

namespace PhpClaw\Memory\Contracts;

use PhpClaw\Memory\MemoryHit;

/**
 * A memory driver that can answer a relevance query itself instead of being scanned through all().
 */
interface SearchableMemoryInterface extends MemoryInterface
{
    /**
     * Find the entries most relevant to the query; how relevance is scored is defined by each driver.
     *
     * @param  string  $query  Free text to match against stored values.
     * @param  int  $limit  Maximum hits to return.
     * @param  string  $namespace  Memory namespace to search.
     * @return list<MemoryHit> Hits in descending score order, at most $limit.
     */
    public function search(string $query, int $limit = 5, string $namespace = 'default'): array;
}
