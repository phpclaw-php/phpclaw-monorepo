<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Benchmarks;

/**
 * Benchmarks the SELECT-only SQL guard and string building in the database tool.
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
final class DatabaseBench
{
    /**
     * Benchmark the SELECT-only assertion over a candidate statement.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_select_only_guard(): void
    {
        $sql = 'SELECT id, role, content FROM phpclaw_messages WHERE conversation_id = ? ORDER BY created_at ASC';
        $trimmed = ltrim($sql);
        $isSelect = stripos($trimmed, 'select') === 0
            && preg_match('/\b(insert|update|delete|drop|alter|truncate|create)\b/i', $trimmed) === 0;
    }

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
            $parts[] = $k.' = ?';
        }
        $sql = 'SELECT * FROM '.$table.' WHERE '.implode(' AND ', $parts);
    }
}
