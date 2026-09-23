<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Benchmarks;

use PhpBench\Attributes as Bench;

/**
 * Benchmarks for tool input validation, keyword checks, and result formatting.
 */
#[Bench\Iterations(3)]
#[Bench\Revs(50)]
final class ToolBench
{
    /**
     * Benchmark validating tool input and coercing the limit.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_tool_input_validation(): void
    {
        $input = ['query' => 'SELECT * FROM #__articles', 'limit' => 10];
        if (! isset($input['query']) || ! is_string($input['query'])) {
            throw new \InvalidArgumentException('query required');
        }
        $limit = (int) ($input['limit'] ?? 100);
    }

    /**
     * Benchmark scanning a query for forbidden SQL keywords.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_tool_keyword_check(): void
    {
        $query = 'SELECT * FROM #__articles WHERE state = 1';
        $forbidden = ['DROP', 'DELETE', 'UPDATE', 'INSERT', 'TRUNCATE', 'ALTER', 'CREATE'];
        $upper = strtoupper($query);
        foreach ($forbidden as $kw) {
            if (preg_match('/\b'.$kw.'\b/', $upper)) {
                break;
            }
        }
    }

    /**
     * Benchmark formatting and JSON-encoding result rows.
     *
     * @return void
     */
    #[Bench\Subject]
    public function bench_tool_result_format(): void
    {
        $rows = array_fill(0, 50, ['id' => 1, 'title' => 'Test', 'state' => 1]);
        $formatted = ['count' => count($rows), 'rows' => array_slice($rows, 0, 10)];
        json_encode($formatted);
    }
}
