<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Symfony\SymfonyIdentityResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class SymfonyIdentityResolverFailClosedTest extends TestCase
{
    public function test_acting_user_is_anonymous_when_the_host_app_has_no_security_bundle(): void
    {
        $resolver = new SymfonyIdentityResolver;

        self::assertSame('', $resolver->actingUserId());
    }

    public function test_acting_user_is_anonymous_when_the_token_storage_throws(): void
    {
        $resolver = new SymfonyIdentityResolver($this->throwingTokenStorage(), null);

        self::assertSame('', $resolver->actingUserId());
    }

    public function test_manage_all_is_refused_when_the_host_app_has_no_security_bundle(): void
    {
        $resolver = new SymfonyIdentityResolver;

        self::assertFalse($resolver->manageAll());
        self::assertFalse($resolver->hasRole(SymfonyIdentityResolver::CHAT_ROLE));
    }

    public function test_role_is_refused_when_the_authorization_checker_throws(): void
    {
        $resolver = new SymfonyIdentityResolver(null, $this->throwingChecker());

        self::assertFalse($resolver->hasRole(SymfonyIdentityResolver::CHAT_ROLE));
        self::assertFalse($resolver->manageAll());
    }

    public function test_a_throwing_checker_still_refuses_while_impersonating_a_queued_user(): void
    {
        $resolver = new SymfonyIdentityResolver($this->throwingTokenStorage(), $this->throwingChecker());
        $resolver->actAs('alice@example.com');

        self::assertSame('alice@example.com', $resolver->actingUserId());
        self::assertTrue($resolver->isImpersonating());
        self::assertFalse($resolver->manageAll());

        $resolver->stopActing();

        self::assertFalse($resolver->isImpersonating());
        self::assertSame('', $resolver->actingUserId());
    }

    private function throwingTokenStorage(): TokenStorageInterface
    {
        return new class implements TokenStorageInterface
        {
            public function getToken(): ?TokenInterface
            {
                throw new \RuntimeException('No firewall is active for this request.');
            }
        };
    }

    private function throwingChecker(): AuthorizationCheckerInterface
    {
        return new class implements AuthorizationCheckerInterface
        {
            public function isGranted(mixed $attribute, mixed $subject = null): bool
            {
                throw new \RuntimeException('The security context contains no authentication token.');
            }
        };
    }
}
