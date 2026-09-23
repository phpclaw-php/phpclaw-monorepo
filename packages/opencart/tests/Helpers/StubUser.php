<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Helpers;

final class StubUser
{
    public function __construct(
        private readonly int $id,
        private readonly array $permissions = [],
    ) {}

    public function hasPermission(string $key, string $value): bool
    {
        return in_array($value, $this->permissions[$key] ?? [], true);
    }

    public function getId(): int
    {
        return $this->id;
    }
}
