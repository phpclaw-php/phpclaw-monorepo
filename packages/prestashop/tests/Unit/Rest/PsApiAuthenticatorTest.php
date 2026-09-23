<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Rest;

use PhpClaw\PrestaShop\Rest\PsApiAuthenticator;
use PhpClaw\PrestaShop\Rest\PsApiToken;
use PhpClaw\PrestaShop\Tests\Helpers\FakeTokenDb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsApiAuthenticator::class)]
final class PsApiAuthenticatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Configuration::reset();
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_SEC_FETCH_SITE'],
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['HTTP_REFERER'],
            $_SERVER['HTTP_HOST'],
        );
    }

    protected function tearDown(): void
    {
        \Configuration::reset();
        unset(
            $_SERVER['HTTP_AUTHORIZATION'],
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'],
            $_SERVER['HTTP_SEC_FETCH_SITE'],
            $_SERVER['HTTP_ORIGIN'],
            $_SERVER['HTTP_REFERER'],
            $_SERVER['HTTP_HOST'],
        );
        parent::tearDown();
    }

    public function test_is_not_authenticated_when_no_bearer_and_no_cookie(): void
    {
        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated());
    }

    public function test_is_not_authenticated_when_bearer_header_but_no_stored_token(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer some-token';

        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated());
    }

    public function test_session_rejected_for_cross_site_request(): void
    {
        $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';

        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated());

        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
    }

    public function test_session_rejected_when_no_origin_headers_are_sent(): void
    {
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
        $_SERVER['HTTP_HOST'] = 'shop.example.com';

        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated(), 'Session auth must fail closed when same-origin cannot be proven.');
    }

    public function test_session_rejected_when_origin_host_differs(): void
    {
        unset($_SERVER['HTTP_SEC_FETCH_SITE']);
        $_SERVER['HTTP_HOST'] = 'shop.example.com';
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example.net';

        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated());
    }

    public function test_session_rejected_when_referer_host_differs(): void
    {
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN']);
        $_SERVER['HTTP_HOST'] = 'shop.example.com';
        $_SERVER['HTTP_REFERER'] = 'https://evil.example.net/attack.html';

        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated());
    }

    public function test_bearer_token_accepted_from_cgi_redirect_header(): void
    {
        $tokens = $this->seedChatTierToken($token);

        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer '.$token;

        $auth = new PsApiAuthenticator($tokens);

        self::assertTrue($auth->isTokenAuthenticated());
    }

    public function test_bearer_token_is_immune_to_missing_origin_headers(): void
    {
        $tokens = $this->seedChatTierToken($token);

        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER']);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token;

        $auth = new PsApiAuthenticator($tokens);

        self::assertTrue($auth->isAuthenticated(), 'A Bearer client carries no cookie and is not a CSRF target.');
    }

    public function test_is_not_authenticated_when_malformed_bearer_header(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic dXNlcjpwYXNz';

        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated());
    }

    private function seedChatTierToken(?string &$token): PsApiToken
    {
        \Tab::$idsByClass['AdminPhpClawDebug'] = 7;
        \Profile::grant(4, 7, 'view');
        \Employee::seed(42, ['id_profile' => 4, 'email' => 'chat@example.test', 'active' => true]);

        $tokens = new PsApiToken(new FakeTokenDb, 'ps_');
        $token = $tokens->issue(42, 'test');

        return $tokens;
    }
}
