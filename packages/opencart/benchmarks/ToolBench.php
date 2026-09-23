<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Benchmarks;

/**
 * @Iterations(3)
 *
 * @Revs(50)
 */
final class ToolBench
{
    /**
     * @Subject
     *
     * @return void
     */
    public function bench_tool_input_validation(): void
    {
        $input = ['query' => 'SELECT * FROM oc_product', 'limit' => 10];
        if (! isset($input['query']) || ! is_string($input['query'])) {
            throw new \InvalidArgumentException('query required');
        }
        $limit = (int) ($input['limit'] ?? 100);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_tool_keyword_check(): void
    {
        $query = 'SELECT * FROM oc_product WHERE status = 1';
        $forbidden = ['DROP', 'DELETE', 'UPDATE', 'INSERT', 'TRUNCATE', 'ALTER', 'CREATE'];
        $upper = strtoupper($query);
        foreach ($forbidden as $kw) {
            if (preg_match('/\b'.$kw.'\b/', $upper)) {
                break;
            }
        }
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_tool_result_format(): void
    {
        $rows = array_fill(0, 50, ['id' => 1, 'title' => 'Test', 'state' => 1]);
        $formatted = ['count' => count($rows), 'rows' => array_slice($rows, 0, 10)];
        json_encode($formatted);
    }
}
