<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Benchmarks;

/**
 * @Iterations(3)
 *
 * @Revs(100)
 */
final class DatabaseBench
{
    /**
     * Benchmark WHERE-clause SQL string building.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_query_string_build(): void
    {
        $table = 'phpclaw_messages';
        $where = ['conversation_id' => 'abc123', 'role' => 'user'];
        $parts = [];
        foreach ($where as $k => $v) {
            $parts[] = "{$k} = ?";
        }
        $sql = 'SELECT * FROM '.$table.' WHERE '.implode(' AND ', $parts);
    }

    /**
     * Benchmark named parameter binding.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_param_binding(): void
    {
        $params = ['conversation_id' => 'abc123', 'role' => 'user', 'limit' => 50];
        $bound = [];
        foreach ($params as $k => $v) {
            $bound[':'.$k] = is_int($v) ? (int) $v : (string) $v;
        }
    }

    /**
     * Benchmark hydrating result rows into objects.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_result_hydration(): void
    {
        $rows = array_fill(0, 50, ['id' => '01H1', 'conversation_id' => 'abc', 'content' => 'msg', 'created_at' => '2026-01-01']);
        $hydrated = array_map(fn ($r) => (object) $r, $rows);
    }
}
