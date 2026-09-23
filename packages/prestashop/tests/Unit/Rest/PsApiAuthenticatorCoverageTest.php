<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Rest;

use PhpClaw\PrestaShop\Rest\PsApiAuthenticator;
use PhpClaw\PrestaShop\Rest\PsApiToken;
use PhpClaw\PrestaShop\Tests\Helpers\FakeTokenDb;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PsApiAuthenticator::class)]
final class PsApiAuthenticatorCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Configuration::reset();
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_REQUEST['api_token']);
    }

    protected function tearDown(): void
    {
        \Configuration::reset();
        unset($_SERVER['HTTP_AUTHORIZATION']);
        unset($_REQUEST['api_token']);

        parent::tearDown();
    }

    public function test_is_not_authenticated_when_bearer_prefix_wrong_case(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'bearer lowercase-token';

        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated());
    }

    public function test_is_not_authenticated_with_empty_authorization_header(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = '';

        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated());
    }

    public function test_is_not_authenticated_with_only_bearer_keyword(): void
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ';

        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated());
    }

    public function test_is_authenticated_without_any_server_vars_returns_false(): void
    {
        $pristine = $_SERVER;
        unset($_SERVER['HTTP_AUTHORIZATION']);

        $auth = new PsApiAuthenticator;
        $result = $auth->isAuthenticated();

        $_SERVER = $pristine;

        self::assertFalse($result);
    }

    public function test_is_employee_session_returns_false_without_cookie(): void
    {
        $auth = new PsApiAuthenticator;

        $ref = new \ReflectionMethod(PsApiAuthenticator::class, 'isEmployeeSession');
        $ref->setAccessible(true);

        self::assertFalse($ref->invoke($auth));
    }

    public function test_a_valid_token_in_the_query_string_is_still_rejected(): void
    {
        \Tab::$idsByClass['AdminPhpClawDebug'] = 7;
        \Profile::grant(4, 7, 'view');
        \Employee::seed(42, ['id_profile' => 4, 'email' => 'chat@example.test', 'active' => true]);

        $tokens = new PsApiToken(new FakeTokenDb, 'ps_');
        $token = $tokens->issue(42, 'url leak probe');

        $_REQUEST['api_token'] = $token;

        $auth = new PsApiAuthenticator($tokens);

        self::assertFalse($auth->isAuthenticated(), 'A URL-embedded token can leak through access logs and must never authenticate.');
    }

    public function test_is_not_authenticated_with_unknown_query_param_token(): void
    {
        $_REQUEST['api_token'] = 'wrong-token';

        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isAuthenticated());
    }

    public function test_is_token_authenticated_false_without_stored_token(): void
    {
        $auth = new PsApiAuthenticator;

        self::assertFalse($auth->isTokenAuthenticated());
    }
}
