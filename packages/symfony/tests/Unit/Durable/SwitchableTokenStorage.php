<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Durable;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class SwitchableTokenStorage implements TokenStorageInterface
{
    public function __construct(private readonly SwitchableUser $user) {}

    public function getToken(): ?TokenInterface
    {
        if ($this->user->id === '') {
            return null;
        }

        return new SecurityToken(new SecurityUser($this->user->id));
    }
}
