<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Memory;

use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Memory\Contracts\SearchableMemoryInterface;
use PhpClaw\Memory\MemoryHit;
use PhpClaw\Memory\TokenOverlapScorer;
use PHPUnit\Framework\TestCase;

final class TokenOverlapScorerTest extends TestCase
{
    public function test_it_drops_words_shorter_than_three_characters(): void
    {
        $this->assertSame(['the', 'cat', 'sat', 'mat'], TokenOverlapScorer::keywords('the cat sat on a mat'));
    }

    public function test_it_is_case_insensitive_and_dedupes(): void
    {
        $this->assertSame(['dark', 'mode'], TokenOverlapScorer::keywords('Dark MODE dark mode'));
    }

    public function test_it_scores_full_term_match_highest(): void
    {
        $hits = TokenOverlapScorer::rank(
            ['partial' => 'user likes dark themes', 'full' => 'user likes dark mode', 'none' => 'shipping address'],
            'user dark mode',
            5,
            'prefs',
        );

        $this->assertSame(['full', 'partial'], array_map(static fn (MemoryHit $hit): string => $hit->key, $hits));
        $this->assertSame(3.0, $hits[0]->score);
        $this->assertSame(2.0, $hits[1]->score);
        $this->assertSame('prefs', $hits[0]->namespace);
        $this->assertSame('user likes dark mode', $hits[0]->value);
    }

    public function test_it_respects_the_limit(): void
    {
        $hits = TokenOverlapScorer::rank(['a' => 'red apple', 'b' => 'red berry', 'c' => 'red cherry'], 'red', 2, 'default');

        $this->assertCount(2, $hits);
    }

    public function test_a_limit_of_zero_returns_nothing(): void
    {
        $this->assertSame([], TokenOverlapScorer::rank(['a' => 'red apple'], 'red', 0, 'default'));
    }

    public function test_it_returns_empty_when_nothing_overlaps(): void
    {
        $this->assertSame([], TokenOverlapScorer::rank(['a' => 'red apple'], 'blue ocean', 5, 'default'));
    }

    public function test_it_scores_json_encoded_non_string_values_and_casts_int_keys(): void
    {
        $hits = TokenOverlapScorer::rank([7 => ['city' => 'Toronto']], 'toronto weather', 5, 'default');

        $this->assertSame('7', $hits[0]->key);
        $this->assertSame(['city' => 'Toronto'], $hits[0]->value);
        $this->assertSame('{"city":"Toronto"}', TokenOverlapScorer::text($hits[0]->value));
    }

    public function test_search_asks_a_searchable_driver_and_never_scans_it(): void
    {
        $hit = new MemoryHit('k', 'v', 'default', 9.0);
        $driver = $this->createMock(SearchableMemoryInterface::class);
        $driver->expects($this->once())->method('search')->with('query text', 4, 'notes')->willReturn([$hit]);
        $driver->expects($this->never())->method('all');

        $this->assertSame([$hit], TokenOverlapScorer::search($driver, 'query text', 4, 'notes'));
    }

    public function test_search_ranks_all_entries_of_a_plain_driver(): void
    {
        $driver = $this->createMock(MemoryInterface::class);
        $driver->expects($this->once())->method('all')->with('notes')->willReturn(['k' => 'query text here']);

        $hits = TokenOverlapScorer::search($driver, 'query text', 4, 'notes');

        $this->assertSame('k', $hits[0]->key);
        $this->assertSame(2.0, $hits[0]->score);
    }

    public function test_search_ranks_a_real_array_memory_through_its_own_search(): void
    {
        $memory = new ArrayMemory;
        $memory->set('city', 'user lives in Toronto');

        $this->assertSame('city', TokenOverlapScorer::search($memory, 'where does the user live toronto', 3, 'default')[0]->key);
    }
}
