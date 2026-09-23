<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Support;

use PhpClaw\Symfony\SymfonyIdentityResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

trait BuildsIdentity
{
    private function buildIdentity(string $userId, bool $manageAll = false): SymfonyIdentityResolver
    {
        $user = $userId === '' ? null : new class($userId) implements UserInterface
        {
            public function __construct(private readonly string $id) {}

            public function getUserIdentifier(): string
            {
                return $this->id;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }
        };

        $tokenStorage = new class($user) implements TokenStorageInterface
        {
            public function __construct(private readonly ?UserInterface $user) {}

            public function getToken(): ?TokenInterface
            {
                if ($this->user === null) {
                    return null;
                }

                return new class($this->user) implements TokenInterface
                {
                    public function __construct(private readonly UserInterface $user) {}

                    public function getUser(): ?UserInterface
                    {
                        return $this->user;
                    }
                };
            }
        };

        $checker = new class($manageAll) implements AuthorizationCheckerInterface
        {
            public function __construct(private readonly bool $granted) {}

            public function isGranted(mixed $attribute, mixed $subject = null): bool
            {
                return $attribute === SymfonyIdentityResolver::MANAGE_ALL_ROLE && $this->granted;
            }
        };

        return new SymfonyIdentityResolver($tokenStorage, $checker);
    }
}
