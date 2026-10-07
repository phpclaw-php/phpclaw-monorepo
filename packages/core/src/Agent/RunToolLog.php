<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

/**
 * What a durable run has done with its tools: the names called, the calls that failed and the files read.
 */
final class RunToolLog
{
    /**
     * Build a tool log.
     *
     * @param  list<string>  $called  Tool names called so far, in order.
     * @param  array<string, bool>  $failed  Signatures of calls that failed so far.
     * @param  array<string, list<string>>  $readPaths  Canonical paths read so far, keyed by workspace root.
     * @return void
     */
    public function __construct(
        public readonly array $called = [],
        public readonly array $failed = [],
        public readonly array $readPaths = [],
    ) {}

    /**
     * Serialise to plain data.
     *
     * @return array{called: list<string>, failed: array<string, bool>, read_paths: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return ['called' => $this->called, 'failed' => $this->failed, 'read_paths' => $this->readPaths];
    }

    /**
     * Rebuild from toArray() output.
     *
     * @param  array<string, mixed>  $data  Serialised log.
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_values(array_map('strval', (array) ($data['called'] ?? []))),
            (array) ($data['failed'] ?? []),
            (array) ($data['read_paths'] ?? []),
        );
    }
}
