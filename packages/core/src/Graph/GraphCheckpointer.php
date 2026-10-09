<?php

declare(strict_types=1);

namespace PhpClaw\Graph;

use PhpClaw\Agent\RunStore;
use PhpClaw\Exceptions\GraphException;
use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Saves every snapshot of a graph thread through a memory driver and reads them back, oldest first.
 */
final class GraphCheckpointer
{
    public const NAMESPACE = 'graph_threads';

    /**
     * Use the given memory driver for every thread.
     *
     * @param  MemoryInterface  $memory  Driver the snapshots are stored through.
     */
    public function __construct(
        private readonly MemoryInterface $memory,
    ) {}

    /**
     * Append the snapshot to its thread's history, after checking every saved value is plain data.
     *
     * @param  GraphSnapshot  $snapshot  Snapshot to save.
     * @return void
     *
     * @throws GraphException When a state value or the interrupt value is an object, a closure or a resource.
     */
    public function save(GraphSnapshot $snapshot): void
    {
        foreach ($snapshot->values as $key => $value) {
            $this->assertSavable($value, sprintf("state key '%s'", $key));
        }

        $this->assertSavable($snapshot->interrupt, 'interrupt value');
        $this->assertSavable($snapshot->answers, 'resume answer');

        $rows = $this->rows($snapshot->threadId);
        $rows[] = $snapshot->toArray();

        $this->memory->set($snapshot->threadId, $rows, self::NAMESPACE, RunStore::TTL_SECONDS);
    }

    /**
     * Return the thread's newest snapshot, or null when nothing was saved.
     *
     * @param  string  $threadId  Thread id.
     * @return GraphSnapshot|null
     */
    public function latest(string $threadId): ?GraphSnapshot
    {
        $rows = $this->rows($threadId);

        return $rows === [] ? null : GraphSnapshot::fromArray($threadId, $rows[array_key_last($rows)]);
    }

    /**
     * Return every saved snapshot of the thread, oldest first.
     *
     * @param  string  $threadId  Thread id.
     * @return list<GraphSnapshot>
     */
    public function history(string $threadId): array
    {
        return array_map(static fn (array $row): GraphSnapshot => GraphSnapshot::fromArray($threadId, $row), $this->rows($threadId));
    }

    /**
     * Return the newest snapshot of every saved thread, keyed by thread id.
     *
     * @return array<string, GraphSnapshot>
     */
    public function threads(): array
    {
        $threads = [];

        foreach (array_keys($this->memory->all(self::NAMESPACE)) as $threadId) {
            $latest = $this->latest((string) $threadId);

            if ($latest !== null) {
                $threads[(string) $threadId] = $latest;
            }
        }

        return $threads;
    }

    /**
     * Read the thread's stored rows.
     *
     * @param  string  $threadId  Thread id.
     * @return list<array<string, mixed>>
     */
    private function rows(string $threadId): array
    {
        $stored = $this->memory->get($threadId, self::NAMESPACE);

        return is_array($stored) ? array_values(array_filter($stored, 'is_array')) : [];
    }

    /**
     * Throw unless the value is null, a scalar, or an array holding only those.
     *
     * @param  mixed  $value  Value to check.
     * @param  string  $where  Where the value sits, for the error message.
     * @return void
     *
     * @throws GraphException When the value cannot be saved.
     */
    private function assertSavable(mixed $value, string $where): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertSavable($item, $where);
            }

            return;
        }

        if ($value !== null && ! is_scalar($value)) {
            throw GraphException::unsavableValue($where);
        }
    }
}
