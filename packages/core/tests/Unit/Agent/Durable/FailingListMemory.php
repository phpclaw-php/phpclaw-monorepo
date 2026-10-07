<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent\Durable;

use PhpClaw\Memory\Contracts\MemoryInterface;

final class FailingListMemory implements MemoryInterface
{
    public function get(string $key, string $namespace = 'default'): mixed
    {
        return null;
    }

    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void {}

    public function forget(string $key, string $namespace = 'default'): void {}

    public function flush(string $namespace = 'default'): void {}

    public function all(string $namespace = 'default'): array
    {
        throw new \RuntimeException('database gone');
    }

    public function has(string $key, string $namespace = 'default'): bool
    {
        return false;
    }
}
