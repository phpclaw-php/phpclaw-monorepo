<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent\Durable;

use PhpClaw\Agent\RunStore;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class RecordingMemory implements MemoryInterface
{
    public array $saves = [];

    private ArrayMemory $inner;

    public function __construct()
    {
        $this->inner = new ArrayMemory;
    }

    public function get(string $key, string $namespace = 'default'): mixed
    {
        return $this->inner->get($key, $namespace);
    }

    public function set(string $key, mixed $value, string $namespace = 'default', ?int $ttl = null): void
    {
        if ($namespace === RunStore::NAMESPACE) {
            $this->saves[] = ['value' => $value, 'ttl' => $ttl];
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
