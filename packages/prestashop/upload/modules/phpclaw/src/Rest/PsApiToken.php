<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Rest;

use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Db\PsDbAdapter;

/**
 * Per-employee REST API tokens: issued once in plaintext, stored only as a sha256 hash.
 */
final class PsApiToken
{
    private const TOKEN_BYTES = 24;

    private readonly string $table;

    /**
     * Create a new PsApiToken store.
     *
     * @param  PsDbInterface  $db
     * @param  string  $tablePrefix
     * @return void
     */
    public function __construct(
        private readonly PsDbInterface $db,
        string $tablePrefix = '',
    ) {
        $this->table = $tablePrefix.'phpclaw_api_token';
    }

    /**
     * Build a store bound to PrestaShop's own database singleton.
     *
     * @return self|null Null when PrestaShop's `\Db` is unavailable.
     */
    public static function fromPrestaShop(): ?self
    {
        if (! class_exists(\Db::class)) {
            return null;
        }

        $prefix = defined('_DB_PREFIX_') ? (string) _DB_PREFIX_ : '';

        return new self(new PsDbAdapter(\Db::getInstance()), $prefix);
    }

    /**
     * Hash a plaintext token for storage and lookup.
     *
     * @param  string  $token  Plaintext token.
     * @return string Lowercase sha256 hex digest.
     */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Issue a new token for one employee and return the plaintext exactly once.
     *
     * @param  int  $employeeId  Owning employee id.
     * @param  string  $label  Operator-facing label, truncated to 64 characters.
     * @return string Plaintext token, never stored and never recoverable afterwards.
     */
    public function issue(int $employeeId, string $label = ''): string
    {
        $token = bin2hex(random_bytes(self::TOKEN_BYTES));

        $this->db->query(
            "INSERT INTO {$this->table} (id_employee, label, token_hash, created_at)
             VALUES (?, ?, ?, ?)",
            [$employeeId, mb_substr(trim($label), 0, 64), self::hash($token), date('Y-m-d H:i:s')],
        );

        return $token;
    }

    /**
     * Resolve a presented plaintext token to the employee it belongs to.
     *
     * @param  string  $token  Plaintext token as presented by the caller.
     * @return int Employee id, or 0 when the token is unknown or blank.
     */
    public function resolveEmployeeId(string $token): int
    {
        if (trim($token) === '') {
            return 0;
        }

        $rows = $this->db->query(
            "SELECT id_employee FROM {$this->table} WHERE token_hash = ? LIMIT 1",
            [self::hash($token)],
        )->rows;

        return isset($rows[0]['id_employee']) ? (int) $rows[0]['id_employee'] : 0;
    }

    /**
     * Stamp the last-used timestamp for a presented token.
     *
     * @param  string  $token  Plaintext token as presented by the caller.
     * @return void
     */
    public function touch(string $token): void
    {
        if (trim($token) === '') {
            return;
        }

        $this->db->query(
            "UPDATE {$this->table} SET last_used_at = ? WHERE token_hash = ?",
            [date('Y-m-d H:i:s'), self::hash($token)],
        );
    }

    /**
     * Revoke one token by its row id.
     *
     * @param  int  $tokenId  Row id from {@see self::all()}.
     * @return void
     */
    public function revoke(int $tokenId): void
    {
        $this->db->query(
            "DELETE FROM {$this->table} WHERE id_api_token = ?",
            [$tokenId],
        );
    }

    /**
     * List every issued token, newest first, without any hash or plaintext.
     *
     * @return list<array{id: int, id_employee: int, label: string, created_at: string, last_used_at: string}>
     */
    public function all(): array
    {
        $rows = $this->db->query(
            "SELECT id_api_token, id_employee, label, created_at, last_used_at
             FROM {$this->table}
             ORDER BY created_at DESC, id_api_token DESC",
        )->rows;

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) ($row['id_api_token'] ?? 0),
                'id_employee' => (int) ($row['id_employee'] ?? 0),
                'label' => (string) ($row['label'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
                'last_used_at' => (string) ($row['last_used_at'] ?? ''),
            ];
        }

        return $out;
    }
}
