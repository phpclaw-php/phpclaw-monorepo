<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Support;

use Magento\Framework\App\CacheInterface;

final class ArrayAppCache implements CacheInterface
{
    public array $entries = [];

    public function load(string $identifier): mixed
    {
        return $this->entries[$identifier]['data'] ?? false;
    }

    public function save(string $data, string $identifier, array $tags = [], ?int $lifeTime = null): bool
    {
        $this->entries[$identifier] = ['data' => $data, 'tags' => $tags, 'lifetime' => $lifeTime];

        return true;
    }

    public function remove(string $identifier): bool
    {
        unset($this->entries[$identifier]);

        return true;
    }

    public function clean(array $tags = []): bool
    {
        foreach ($this->entries as $id => $entry) {
            if (array_intersect($tags, $entry['tags']) !== []) {
                unset($this->entries[$id]);
            }
        }

        return true;
    }
}
