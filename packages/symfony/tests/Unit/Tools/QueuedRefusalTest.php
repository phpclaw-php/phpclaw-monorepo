<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Tools;

use PhpClaw\Symfony\SymfonyIdentityResolver;
use PhpClaw\Symfony\Tests\Support\BuildsIdentity;
use PhpClaw\Symfony\Tools\DatabaseTool;
use PHPUnit\Framework\TestCase;

final class QueuedRefusalTest extends TestCase
{
    use BuildsIdentity;

    public function test_a_role_gated_tool_inside_a_queued_run_says_why(): void
    {
        $identity = $this->buildIdentity('alice', manageAll: true);
        $identity->actAs('alice');

        $decoded = $this->runDatabaseTool($identity);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN_IN_QUEUED_RUN', $decoded['error']['code']);
        self::assertStringContainsString(SymfonyIdentityResolver::MANAGE_ALL_ROLE, $decoded['error']['message']);
        self::assertStringContainsString('asynchronously', $decoded['error']['message']);
        self::assertStringContainsString('synchronously', $decoded['error']['remedy']);
        self::assertSame('alice', $decoded['error']['acting_user']);
    }

    public function test_an_ordinary_permission_refusal_is_unchanged(): void
    {
        $decoded = $this->runDatabaseTool($this->buildIdentity('bob'));

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertArrayNotHasKey('remedy', $decoded['error']);
    }

    public function test_the_two_refusals_do_not_read_the_same(): void
    {
        $queued = $this->buildIdentity('alice', manageAll: true);
        $queued->actAs('alice');

        $queuedMessage = $this->runDatabaseTool($queued)['error']['message'];
        $ordinaryMessage = $this->runDatabaseTool($this->buildIdentity('bob'))['error']['message'];

        self::assertNotSame($ordinaryMessage, $queuedMessage);
    }

    public function test_the_same_user_reaches_the_tool_synchronously(): void
    {
        $identity = $this->buildIdentity('alice', manageAll: true);

        $decoded = $this->runDatabaseTool($identity);

        self::assertNotSame('FORBIDDEN', $decoded['error']['code'] ?? null);
        self::assertNotSame('FORBIDDEN_IN_QUEUED_RUN', $decoded['error']['code'] ?? null);
    }

    public function test_stopping_the_queued_run_restores_the_synchronous_answer(): void
    {
        $identity = $this->buildIdentity('alice', manageAll: true);

        $identity->actAs('alice');
        self::assertSame('FORBIDDEN_IN_QUEUED_RUN', $this->runDatabaseTool($identity)['error']['code']);

        $identity->stopActing();
        self::assertNotSame('FORBIDDEN_IN_QUEUED_RUN', $this->runDatabaseTool($identity)['error']['code'] ?? null);
    }

    private function runDatabaseTool(?SymfonyIdentityResolver $identity): array
    {
        $tool = new DatabaseTool(null, null, $identity);

        try {
            $raw = $tool->execute(['sql' => 'SELECT 1']);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => ['code' => 'THREW', 'message' => $e->getMessage()]];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['success' => false, 'error' => ['code' => 'NOT_JSON']];
    }

    public function test_an_app_with_no_security_bundle_cannot_run_raw_sql(): void
    {
        $decoded = $this->runDatabaseTool(new SymfonyIdentityResolver(null, null));

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
    }

    public function test_a_null_authorization_checker_never_grants_the_manage_all_role(): void
    {
        $identity = new SymfonyIdentityResolver(null, null);

        self::assertFalse($identity->manageAll());
        self::assertFalse($identity->hasRole(SymfonyIdentityResolver::MANAGE_ALL_ROLE));
    }
}
