<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Durable;

use Symfony\Component\Security\Core\User\UserInterface;

final class SecurityUser implements UserInterface
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
}
