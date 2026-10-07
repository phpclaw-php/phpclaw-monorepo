<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Durable;

use PhpClaw\Symfony\SymfonyIdentityResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class SwitchableAuthorizationChecker implements AuthorizationCheckerInterface
{
    public function __construct(private readonly SwitchableUser $user) {}

    public function isGranted(mixed $attribute, mixed $subject = null): bool
    {
        return $attribute === SymfonyIdentityResolver::MANAGE_ALL_ROLE && $this->user->manageAll;
    }
}
