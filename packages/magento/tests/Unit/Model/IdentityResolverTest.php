<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Model;

use Magento\Authorization\Model\UserContextInterface;
use Magento\Backend\Model\Auth\Session;
use Magento\Framework\App\State;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\User\Model\User;
use PhpClaw\Magento\Model\IdentityResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class IdentityResolverTest extends TestCase
{
    private State&MockObject $appState;

    private Session&MockObject $authSession;

    private AuthorizationInterface&MockObject $authorization;

    private UserContextInterface&MockObject $userContext;

    protected function setUp(): void
    {
        $this->appState = $this->createMock(State::class);
        $this->authSession = $this->createMock(Session::class);
        $this->authorization = $this->createMock(AuthorizationInterface::class);
        $this->userContext = $this->createMock(UserContextInterface::class);
    }

    private function resolver(): IdentityResolver
    {
        return new IdentityResolver(
            $this->appState,
            $this->authSession,
            $this->authorization,
            $this->userContext,
        );
    }

    public function test_admin_session_user_is_the_acting_identity(): void
    {
        $this->appState->method('getAreaCode')->willReturn('adminhtml');
        $this->authSession->method('getUser')->willReturn(new User(42));

        self::assertSame(42, $this->resolver()->actingUserId());
    }

    public function test_web_api_falls_back_to_the_user_context(): void
    {
        $this->appState->method('getAreaCode')->willReturn('webapi_rest');
        $this->authSession->method('getUser')->willReturn(null);
        $this->userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_ADMIN);
        $this->userContext->method('getUserId')->willReturn(13);

        self::assertSame(13, $this->resolver()->actingUserId());
    }

    public function test_non_admin_user_context_is_not_an_identity(): void
    {
        $this->appState->method('getAreaCode')->willReturn('webapi_rest');
        $this->authSession->method('getUser')->willReturn(null);
        $this->userContext->method('getUserType')->willReturn(UserContextInterface::USER_TYPE_CUSTOMER);
        $this->userContext->method('getUserId')->willReturn(99);

        self::assertSame(0, $this->resolver()->actingUserId());
    }

    public function test_console_run_is_unowned(): void
    {
        $this->appState->method('getAreaCode')->willReturn('global');
        $this->authSession->method('getUser')->willReturn(new User(42));

        self::assertSame(0, $this->resolver()->actingUserId());
    }

    public function test_cron_run_is_unowned(): void
    {
        $this->appState->method('getAreaCode')->willReturn('crontab');
        $this->authSession->method('getUser')->willReturn(new User(42));

        self::assertSame(0, $this->resolver()->actingUserId());
    }

    public function test_unset_area_is_unowned(): void
    {
        $this->appState->method('getAreaCode')->willThrowException(new LocalizedException(new Phrase('area not set')));

        self::assertSame(0, $this->resolver()->actingUserId());
        self::assertFalse($this->resolver()->manageAll());
    }

    public function test_a_session_that_blows_up_yields_no_identity(): void
    {
        $this->appState->method('getAreaCode')->willReturn('adminhtml');
        $this->authSession->method('getUser')->willThrowException(new \RuntimeException('no session'));
        $this->userContext->method('getUserType')->willReturn(null);

        self::assertSame(0, $this->resolver()->actingUserId());
    }

    public function test_manage_all_follows_the_settings_acl_resource(): void
    {
        $this->appState->method('getAreaCode')->willReturn('adminhtml');
        $this->authorization->method('isAllowed')
            ->willReturnCallback(static fn (string $r): bool => $r === IdentityResolver::MANAGE_ALL_RESOURCE);

        self::assertTrue($this->resolver()->manageAll());
    }

    public function test_manage_all_is_false_without_the_resource(): void
    {
        $this->appState->method('getAreaCode')->willReturn('adminhtml');
        $this->authorization->method('isAllowed')->willReturn(false);

        self::assertFalse($this->resolver()->manageAll());
    }

    public function test_manage_all_is_false_on_the_console(): void
    {
        $this->appState->method('getAreaCode')->willReturn('global');
        $this->authorization->method('isAllowed')->willReturn(true);

        self::assertFalse($this->resolver()->manageAll());
    }

    public function test_manage_all_is_false_when_authorization_throws(): void
    {
        $this->appState->method('getAreaCode')->willReturn('adminhtml');
        $this->authorization->method('isAllowed')->willThrowException(new \RuntimeException('acl down'));

        self::assertFalse($this->resolver()->manageAll());
    }
}
