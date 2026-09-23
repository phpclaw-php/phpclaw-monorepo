<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Benchmarks;

/**
 * Micro-benchmarks for shared tool hot paths: input validation, keyword guard, and result formatting.
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
class ToolBench
{
    private const PREFIX = 'ps_';

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_tool_input_validation(): void
    {
        $input = ['sql' => 'SELECT * FROM '.self::PREFIX.'orders', 'limit' => 25];
        if (! isset($input['sql']) || ! is_string($input['sql'])) {
            throw new \InvalidArgumentException('sql required');
        }
        $limit = min(max(1, (int) ($input['limit'] ?? 50)), 500);
        unset($limit);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_tool_keyword_guard(): void
    {
        $sql = 'SELECT id_order, reference FROM '.self::PREFIX.'orders WHERE current_state = 2';
        $blocked = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'TRUNCATE', 'ALTER', 'GRANT', 'REVOKE', 'CREATE', 'REPLACE', 'RENAME', 'CALL', 'EXEC'];
        $upper = strtoupper(ltrim($sql));
        foreach ($blocked as $kw) {
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
        $rows = array_fill(0, 50, ['id' => 1, 'reference' => 'ABCDE', 'total_paid_tax_incl' => 120.0, 'state' => 'Payment accepted']);
        $formatted = ['total' => count($rows), 'orders' => array_slice($rows, 0, 25)];
        json_encode($formatted, JSON_UNESCAPED_UNICODE);
    }
}
