<?php

declare(strict_types=1);

namespace PhpClaw\Symfony;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Resolves the acting user identifier and whether it may reach every user's conversations.
 */
final class SymfonyIdentityResolver
{
    public const CHAT_ROLE = 'ROLE_PHPCLAW_CHAT';

    public const MANAGE_ALL_ROLE = 'ROLE_PHPCLAW_MANAGE_ALL';

    private ?string $impersonating = null;

    /**
     * Bind the token storage and authorisation checker this resolver reads identity from.
     *
     * @param  TokenStorageInterface|null  $tokenStorage  Null when the host app has no security bundle.
     * @param  AuthorizationCheckerInterface|null  $authorizationChecker  Null when the host app has no security bundle.
     * @return void
     */
    public function __construct(
        private readonly ?TokenStorageInterface $tokenStorage = null,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {}

    /**
     * Act as the given user for a queued run whose worker has no security token; the impersonated
     * identity carries no roles, so role-gated tools stay refused and a queued run never exceeds sync.
     *
     * @param  string  $userId  The dispatching user identifier, or the empty sentinel.
     * @return void
     */
    public function actAs(string $userId): void
    {
        $this->impersonating = $userId;
    }

    /**
     * Stop acting as the queued user, so a long-lived worker cannot carry one job's identity
     * into the next.
     *
     * @return void
     */
    public function stopActing(): void
    {
        $this->impersonating = null;
    }

    /**
     * Whether this resolver is currently acting as a queued user.
     *
     * @return bool
     */
    public function isImpersonating(): bool
    {
        return $this->impersonating !== null;
    }

    /**
     * The authenticated user identifier, or an empty string when there is no logged-in user.
     *
     * @return string
     */
    public function actingUserId(): string
    {
        if ($this->impersonating !== null) {
            return $this->impersonating;
        }

        if ($this->tokenStorage === null) {
            return '';
        }

        try {
            return $this->tokenStorage->getToken()?->getUser()?->getUserIdentifier() ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Whether the acting user may reach every user's conversations.
     *
     * @return bool
     */
    public function manageAll(): bool
    {
        return $this->hasRole(self::MANAGE_ALL_ROLE);
    }

    /**
     * Whether the acting user holds a role. False when the host app has no security bundle.
     *
     * @param  string  $role  Symfony role name, for example ROLE_PHPCLAW_CHAT.
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        if ($this->impersonating !== null) {
            return false;
        }

        if ($this->authorizationChecker === null) {
            return false;
        }

        try {
            return $this->authorizationChecker->isGranted($role);
        } catch (\Throwable) {
            return false;
        }
    }
}
