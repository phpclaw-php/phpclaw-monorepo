<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Benchmarks;

/**
 * Micro-benchmarks for native DB layer hot paths: table-name substitution, positional binding, and row hydration.
 *
 * @Iterations(3)
 *
 * @Revs(50)
 */
class DatabaseBench
{
    private const PREFIX = 'ps_';

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_query_string_build(): void
    {
        $template = 'SELECT * FROM PREFIX_orders o JOIN PREFIX_customer c ON c.id_customer = o.id_customer';
        $sql = str_replace('PREFIX_', self::PREFIX, $template);
        unset($sql);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_param_inline_binding(): void
    {
        $sql = 'SELECT * FROM '.self::PREFIX.'customer WHERE email = ? AND active = ? AND id_customer > ?';
        $params = ['john@example.com', true, 5];
        $index = 0;
        (string) preg_replace_callback(
            '/\?/',
            static function () use ($params, &$index): string {
                $value = $params[$index++] ?? null;
                if ($value === null) {
                    return 'NULL';
                }
                if (is_bool($value)) {
                    return $value ? '1' : '0';
                }
                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }

                return "'".addslashes((string) $value)."'";
            },
            $sql,
        );
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_result_hydration(): void
    {
        $rows = array_fill(0, 50, ['id_product' => '1', 'name' => 'Mug', 'price' => '11.90', 'active' => '1']);
        $hydrated = array_map(static fn (array $r): array => [
            'id' => (int) $r['id_product'],
            'name' => (string) $r['name'],
            'price' => (float) $r['price'],
            'active' => (bool) $r['active'],
        ], $rows);
        unset($hydrated);
    }
}
