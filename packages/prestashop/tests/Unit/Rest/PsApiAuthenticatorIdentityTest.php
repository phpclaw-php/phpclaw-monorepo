<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Rest;

use PhpClaw\PrestaShop\PsIdentityResolver;
use PhpClaw\PrestaShop\Rest\PsApiAuthenticator;
use PhpClaw\PrestaShop\Rest\PsApiToken;
use PhpClaw\PrestaShop\Tests\Helpers\FakeTokenDb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsApiAuthenticator::class)]
final class PsApiAuthenticatorIdentityTest extends TestCase
{
    private const CHAT_TAB_ID = 7;

    private FakeTokenDb $db;

    private PsApiToken $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        \Context::reset();
        \Tab::reset();
        \Profile::reset();
        \Employee::reset();
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        \Tab::$idsByClass['AdminPhpClawDebug'] = self::CHAT_TAB_ID;
        \Profile::grant(4, self::CHAT_TAB_ID, 'view');

        \Employee::seed(42, ['id_profile' => 4, 'email' => 'chat@example.test', 'active' => true]);
        \Employee::seed(99, ['id_profile' => 2, 'email' => 'nochat@example.test', 'active' => true]);
        \Employee::seed(55, ['id_profile' => 4, 'email' => 'gone@example.test', 'active' => false]);

        $this->db = new FakeTokenDb;
        $this->tokens = new PsApiToken($this->db, 'ps_');
    }

    protected function tearDown(): void
    {
        \Context::reset();
        \Tab::reset();
        \Profile::reset();
        \Employee::reset();
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        parent::tearDown();
    }

    public function test_token_binds_acting_employee(): void
    {
        $token = $this->tokens->issue(42, 'integration');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;

        self::assertTrue((new PsApiAuthenticator($this->tokens))->isAuthenticated());
        self::assertSame(42, PsIdentityResolver::actingEmployeeId());
    }

    public function test_token_for_employee_without_chat_grant_is_refused(): void
    {
        $token = $this->tokens->issue(99, 'no grant');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;

        self::assertFalse((new PsApiAuthenticator($this->tokens))->isAuthenticated());
    }

    public function test_inactive_employee_token_is_refused(): void
    {
        $token = $this->tokens->issue(55, 'deactivated');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;

        self::assertFalse((new PsApiAuthenticator($this->tokens))->isAuthenticated());
    }

    public function test_unknown_token_is_refused(): void
    {
        $this->tokens->issue(42, 'integration');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-real-token';

        self::assertFalse((new PsApiAuthenticator($this->tokens))->isAuthenticated());
        self::assertSame(0, PsIdentityResolver::actingEmployeeId());
    }

    public function test_revoked_token_is_refused(): void
    {
        $token = $this->tokens->issue(42, 'integration');
        $this->tokens->revoke($this->tokens->all()[0]['id']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;

        self::assertFalse((new PsApiAuthenticator($this->tokens))->isAuthenticated());
    }

    public function test_token_without_bearer_prefix_is_refused(): void
    {
        $token = $this->tokens->issue(42, 'integration');
        $_SERVER['HTTP_AUTHORIZATION'] = $token;

        self::assertFalse((new PsApiAuthenticator($this->tokens))->isAuthenticated());
    }

    public function test_successful_token_auth_stamps_last_used(): void
    {
        $token = $this->tokens->issue(42, 'integration');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;

        (new PsApiAuthenticator($this->tokens))->isAuthenticated();

        self::assertNotNull($this->db->rows[0]['last_used_at']);
    }

    public function test_refused_token_leaves_no_bound_identity(): void
    {
        $token = $this->tokens->issue(99, 'no grant');
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;

        (new PsApiAuthenticator($this->tokens))->isAuthenticated();

        self::assertFalse(PsIdentityResolver::canUseChat());
        self::assertSame(0, PsIdentityResolver::actingEmployeeId());
    }

    public function test_token_is_accepted_from_the_cgi_redirect_header(): void
    {
        $token = $this->tokens->issue(42, 'integration');
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer '.$token;

        self::assertTrue((new PsApiAuthenticator($this->tokens))->isAuthenticated());
        self::assertSame(42, PsIdentityResolver::actingEmployeeId());
    }
}
