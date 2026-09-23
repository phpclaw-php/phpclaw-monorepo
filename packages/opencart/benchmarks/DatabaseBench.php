<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Benchmarks;

/**
 * @Iterations(3)
 *
 * @Revs(100)
 */
final class DatabaseBench
{
    /**
     * @Subject
     *
     * @return void
     */
    public function bench_query_string_build(): void
    {
        $table = 'oc_phpclaw_messages';
        $where = ['conv_id' => 'abc123', 'role' => 'user'];
        $parts = [];
        foreach ($where as $k => $v) {
            $parts[] = "{$k} = ?";
        }
        $sql = 'SELECT * FROM '.$table.' WHERE '.implode(' AND ', $parts);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_param_binding(): void
    {
        $params = ['conv_id' => 'abc123', 'role' => 'user', 'limit' => 50];
        $bound = [];
        foreach ($params as $k => $v) {
            $bound[':'.$k] = is_int($v) ? (int) $v : (string) $v;
        }
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_result_hydration(): void
    {
        $rows = array_fill(0, 50, ['id' => '01H1', 'conv_id' => 'abc', 'content' => 'msg', 'created' => '2026-01-01']);
        $hydrated = array_map(fn ($r) => (object) $r, $rows);
    }
}
