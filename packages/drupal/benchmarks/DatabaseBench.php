<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * Benchmarks for query-string building, parameter binding, and result hydration.
 */
#[Bench\Iterations(3)]
#[Bench\Revs(100)]
final class DatabaseBench
{
    /**
     * Benchmark WHERE-clause SQL string building.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_query_string_build(): void
    {
        $table = '#__phpclaw_messages';
        $where = ['conv_id' => 'abc123', 'role' => 'user'];
        $parts = [];
        foreach ($where as $k => $v) {
            $parts[] = "{$k} = ?";
        }
        $sql = 'SELECT * FROM '.$table.' WHERE '.implode(' AND ', $parts);
    }

    /**
     * Benchmark named parameter binding with type coercion.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_param_binding(): void
    {
        $params = ['conv_id' => 'abc123', 'role' => 'user', 'limit' => 50];
        $bound = [];
        foreach ($params as $k => $v) {
            $bound[':'.$k] = is_int($v) ? (int) $v : (string) $v;
        }
    }

    /**
     * Benchmark hydrating result rows into objects.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_result_hydration(): void
    {
        $rows = array_fill(0, 50, ['id' => '01H1', 'conv_id' => 'abc', 'content' => 'msg', 'created' => '2026-01-01']);
        $hydrated = array_map(fn ($r) => (object) $r, $rows);
    }
}
