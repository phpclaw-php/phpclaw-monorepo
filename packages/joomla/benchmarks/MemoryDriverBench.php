<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Benchmarks;

use PhpClaw\Joomla\Component\Administrator\Memory\JoomlaDbMemory;
use PhpClaw\Support\Ulid;

/**
 * @BeforeMethods({"setUp"})
 *
 * @Iterations(3)
 *
 * @Revs(100)
 */
final class MemoryDriverBench
{
    private \ReflectionMethod $serialize;

    private \ReflectionMethod $unserialize;

    private array $history = [];

    private string $encodedHistory = '';

    /**
     * Reach the driver's own value codec and build a realistic conversation payload. The
     * codec is private but runs on every memory read and write, so it is worth measuring.
     *
     * @return void
     */
    public function setUp(): void
    {
        $this->serialize = new \ReflectionMethod(JoomlaDbMemory::class, 'serialize');
        $this->unserialize = new \ReflectionMethod(JoomlaDbMemory::class, 'unserialize');

        $this->history = ['history' => array_map(
            static fn (int $i): array => [
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => 'Turn '.$i.' of a realistic multi-turn conversation about this Joomla site.',
            ],
            range(0, 19),
        )];

        $this->encodedHistory = (string) $this->serialize->invoke(null, $this->history);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_serialize_conversation_payload(): void
    {
        $this->serialize->invoke(null, $this->history);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_unserialize_conversation_payload(): void
    {
        $this->unserialize->invoke(null, $this->encodedHistory);
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_ulid_generation(): void
    {
        Ulid::generate();
    }

    /**
     * @Subject
     *
     * @return void
     */
    public function bench_ulid_validation(): void
    {
        Ulid::isValid('01JQABCDEFGHJKMNPQRSTVWXYZ');
    }
}
