<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Support;

final class MockDatabaseQuery
{
    private array $calls = [];

    public function __call(string $name, array $args): self
    {
        $this->calls[$name] = $args;

        return $this;
    }

    public function __toString(): string
    {
        return 'MOCK_QUERY';
    }

    public function calls(): array
    {
        return $this->calls;
    }
}
