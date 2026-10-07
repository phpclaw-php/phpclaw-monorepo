<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Durable;

use PhpClaw\Agent\RunStore;
use PhpClaw\Exceptions\RunConflictException;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class ConflictingMemory implements MemoryInterface
{
    public bool $armed = false;

    public function __construct(private readonly MemoryInterface $inner) {}

    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->inner->get($key, $namespace);
    }

    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        if ($this->armed && $namespace === RunStore::NAMESPACE) {
            throw new RunConflictException("Run {$key} was saved by another process.");
        }

        $this->inner->set($key, $value, $namespace, $ttl);
    }

    public function forget(string $key, string $namespace = 'default'): void
    {
        $this->inner->forget($key, $namespace);
    }

    public function flush(string $namespace = 'default'): void
    {
        $this->inner->flush($namespace);
    }

    public function all(string $namespace = 'default'): array
    {
        return $this->inner->all($namespace);
    }

    public function has(string $key, string $namespace = 'default'): bool
    {
        return $this->inner->has($key, $namespace);
    }
}
