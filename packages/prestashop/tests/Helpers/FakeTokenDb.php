<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Helpers;

use PhpClaw\PrestaShop\Contracts\PsDbInterface;

final class FakeTokenDb implements PsDbInterface
{
    public array $rows = [];

    public array $statements = [];

    private int $nextId = 1;

    public function query(string $sql, array $params = []): object
    {
        $this->statements[] = $sql;
        $result = new \stdClass;
        $result->rows = [];

        if (str_starts_with(ltrim($sql), 'INSERT')) {
            $this->rows[] = [
                'id_api_token' => $this->nextId++,
                'id_employee' => (int) $params[0],
                'label' => (string) $params[1],
                'token_hash' => (string) $params[2],
                'created_at' => (string) $params[3],
                'last_used_at' => null,
            ];
        } elseif (str_contains($sql, 'SELECT id_employee')) {
            foreach ($this->rows as $row) {
                if ($row['token_hash'] === $params[0]) {
                    $result->rows = [['id_employee' => $row['id_employee']]];
                    break;
                }
            }
        } elseif (str_contains($sql, 'SELECT id_api_token')) {
            $result->rows = $this->rows;
        } elseif (str_starts_with(ltrim($sql), 'UPDATE')) {
            foreach ($this->rows as $i => $row) {
                if ($row['token_hash'] === $params[1]) {
                    $this->rows[$i]['last_used_at'] = $params[0];
                }
            }
        } elseif (str_starts_with(ltrim($sql), 'DELETE')) {
            $this->rows = array_values(array_filter(
                $this->rows,
                static fn (array $r): bool => $r['id_api_token'] !== (int) $params[0],
            ));
        }

        $result->row = $result->rows[0] ?? [];
        $result->num_rows = count($result->rows);

        return $result;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }
}
