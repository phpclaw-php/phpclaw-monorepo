<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Durable;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class SecurityToken implements TokenInterface
{
    public function __construct(private readonly UserInterface $user) {}

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }
}
