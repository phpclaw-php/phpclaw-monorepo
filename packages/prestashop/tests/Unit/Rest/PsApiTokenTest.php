<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Rest;

use PhpClaw\PrestaShop\Rest\PsApiToken;
use PhpClaw\PrestaShop\Tests\Helpers\FakeTokenDb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsApiToken::class)]
final class PsApiTokenTest extends TestCase
{
    private FakeTokenDb $db;

    private PsApiToken $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = new FakeTokenDb;
        $this->tokens = new PsApiToken($this->db, 'ps_');
    }

    public function test_issue_returns_a_plaintext_token_that_resolves_to_its_employee(): void
    {
        $token = $this->tokens->issue(7, 'stock sync');

        self::assertNotSame('', $token);
        self::assertSame(7, $this->tokens->resolveEmployeeId($token));
    }

    public function test_issue_stores_hash_not_plaintext(): void
    {
        $token = $this->tokens->issue(7, 'stock sync');

        self::assertCount(1, $this->db->rows);
        self::assertNotSame($token, $this->db->rows[0]['token_hash']);
        self::assertSame(hash('sha256', $token), $this->db->rows[0]['token_hash']);
    }

    public function test_unknown_token_resolves_to_zero(): void
    {
        $this->tokens->issue(7, 'stock sync');

        self::assertSame(0, $this->tokens->resolveEmployeeId('not-a-real-token'));
    }

    public function test_blank_token_resolves_to_zero_without_querying(): void
    {
        self::assertSame(0, $this->tokens->resolveEmployeeId('   '));
        self::assertSame([], $this->db->statements);
    }

    public function test_revoked_token_no_longer_resolves(): void
    {
        $token = $this->tokens->issue(7, 'stock sync');
        $id = $this->tokens->all()[0]['id'];

        $this->tokens->revoke($id);

        self::assertSame(0, $this->tokens->resolveEmployeeId($token));
    }

    public function test_two_tokens_for_different_employees_resolve_independently(): void
    {
        $a = $this->tokens->issue(7, 'a');
        $b = $this->tokens->issue(9, 'b');

        self::assertSame(7, $this->tokens->resolveEmployeeId($a));
        self::assertSame(9, $this->tokens->resolveEmployeeId($b));
    }

    public function test_touch_stamps_last_used_for_the_presented_token(): void
    {
        $token = $this->tokens->issue(7, 'stock sync');
        self::assertNull($this->db->rows[0]['last_used_at']);

        $this->tokens->touch($token);

        self::assertNotNull($this->db->rows[0]['last_used_at']);
    }

    public function test_all_never_exposes_the_hash(): void
    {
        $this->tokens->issue(7, 'stock sync');

        $listed = $this->tokens->all();

        self::assertCount(1, $listed);
        self::assertArrayNotHasKey('token_hash', $listed[0]);
        self::assertSame('stock sync', $listed[0]['label']);
        self::assertSame(7, $listed[0]['id_employee']);
    }

    public function test_label_is_truncated_to_the_column_width(): void
    {
        $this->tokens->issue(7, str_repeat('x', 200));

        self::assertSame(64, mb_strlen($this->db->rows[0]['label']));
    }
}
