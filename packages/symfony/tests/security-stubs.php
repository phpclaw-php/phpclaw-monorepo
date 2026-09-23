<?php

declare(strict_types=1);

namespace Symfony\Component\Security\Core\User {
    if (! \interface_exists(UserInterface::class, false)) {
        interface UserInterface
        {
            public function getUserIdentifier(): string;

            public function getRoles(): array;
        }
    }
}

namespace Symfony\Component\Security\Core\Authentication\Token {
    use Symfony\Component\Security\Core\User\UserInterface;

    if (! \interface_exists(TokenInterface::class, false)) {
        interface TokenInterface
        {
            public function getUser(): ?UserInterface;
        }
    }
}

namespace Symfony\Component\Security\Core\Authentication\Token\Storage {
    use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

    if (! \interface_exists(TokenStorageInterface::class, false)) {
        interface TokenStorageInterface
        {
            public function getToken(): ?TokenInterface;
        }
    }
}

namespace Symfony\Component\Security\Core\Authorization {
    if (! \interface_exists(AuthorizationCheckerInterface::class, false)) {
        interface AuthorizationCheckerInterface
        {
            public function isGranted(mixed $attribute, mixed $subject = null): bool;
        }
    }
}
