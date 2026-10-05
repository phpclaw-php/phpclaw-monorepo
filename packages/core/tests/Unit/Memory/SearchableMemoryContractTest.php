<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Memory;

use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\SearchableMemoryInterface;
use PhpClaw\Memory\FileMemory;
use PhpClaw\Memory\MemoryHit;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Memory\RouterMemory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchableMemoryContractTest extends TestCase
{
    private static string $fileDir = '';

    protected function tearDown(): void
    {
        if (self::$fileDir !== '' && is_dir(self::$fileDir)) {
            array_map('unlink', glob(self::$fileDir.'/*') ?: []);
            rmdir(self::$fileDir);
        }
        self::$fileDir = '';
    }

    public static function drivers(): array
    {
        return [
            'array' => [static fn (): SearchableMemoryInterface => new ArrayMemory],
            'file' => [static function (): SearchableMemoryInterface {
                self::$fileDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phpclaw_search_'.uniqid();

                return new FileMemory(self::$fileDir);
            }],
            'privacy-aware' => [static fn (): SearchableMemoryInterface => new PrivacyAwareMemory(new ArrayMemory, true)],
            'router' => [static fn (): SearchableMemoryInterface => new RouterMemory(new ArrayMemory, ['notes' => new ArrayMemory])],
        ];
    }

    #[DataProvider('drivers')]
    public function test_it_returns_hits_in_score_order(\Closure $make): void
    {
        $memory = $make();
        $memory->set('one', 'user likes tea');
        $memory->set('three', 'user likes green tea daily');
        $memory->set('two', 'user likes green coffee');

        $hits = $memory->search('user likes green tea', 5);

        $this->assertSame(['three', 'one', 'two'], array_map(static fn (MemoryHit $hit): string => $hit->key, $hits));
        $this->assertSame([4.0, 3.0, 3.0], array_map(static fn (MemoryHit $hit): float => $hit->score, $hits));
    }

    #[DataProvider('drivers')]
    public function test_it_respects_limit(\Closure $make): void
    {
        $memory = $make();
        foreach (['a', 'b', 'c', 'd'] as $key) {
            $memory->set($key, "shipping address entry {$key}");
        }

        $this->assertCount(2, $memory->search('shipping address', 2));
    }

    #[DataProvider('drivers')]
    public function test_it_scopes_to_namespace(\Closure $make): void
    {
        $memory = $make();
        $memory->set('default-key', 'shipping address in default');
        $memory->set('notes-key', 'shipping address in notes', 'notes');

        $hits = $memory->search('shipping address', 5, 'notes');

        $this->assertSame(['notes-key'], array_map(static fn (MemoryHit $hit): string => $hit->key, $hits));
        $this->assertSame('notes', $hits[0]->namespace);
    }

    #[DataProvider('drivers')]
    public function test_it_returns_empty_for_no_match(\Closure $make): void
    {
        $memory = $make();
        $memory->set('k', 'shipping address');

        $this->assertSame([], $memory->search('favourite colour', 5));
    }
}
