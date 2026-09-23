<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Benchmarks;

/**
 * @Iterations(3)
 *
 * @Revs(50)
 */
final class ToolBench
{
    /**
     * Benchmark SQL tool input validation.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_tool_input_validation(): void
    {
        $input = ['query' => 'SELECT * FROM phpclaw_messages', 'limit' => 10];
        if (! isset($input['query']) || ! is_string($input['query'])) {
            throw new \InvalidArgumentException('query required');
        }
        $limit = (int) ($input['limit'] ?? 100);
    }

    /**
     * Benchmark SQL keyword scanning.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_tool_keyword_check(): void
    {
        $query = 'SELECT * FROM phpclaw_messages WHERE role = "user"';
        $forbidden = ['DROP', 'DELETE', 'UPDATE', 'INSERT', 'TRUNCATE', 'ALTER', 'CREATE'];
        $upper = strtoupper($query);
        foreach ($forbidden as $kw) {
            if (preg_match('/\b'.$kw.'\b/', $upper)) {
                break;
            }
        }
    }

    /**
     * Benchmark result-row JSON formatting.
     *
     * @return void
     *
     * @Subject
     */
    public function bench_tool_result_format(): void
    {
        $rows = array_fill(0, 50, ['id' => 1, 'role' => 'user', 'content' => 'Test']);
        $formatted = ['count' => count($rows), 'rows' => array_slice($rows, 0, 10)];
        json_encode($formatted);
    }
}
