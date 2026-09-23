<?php

declare(strict_types=1);

namespace PhpClaw\Hooks;

use PhpClaw\Hooks\Contracts\HookInterface;

/**
 * Append-only JSON-lines debug logger for phpClaw lifecycle events.
 */
final class DebugLogHook implements HookInterface
{
    public const DEFAULT_EVENT_LABEL = 'event';

    private const LINE_TERMINATOR = "\n";

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    private const KEY_TIMESTAMP = 'ts';

    private const KEY_EVENT = 'event';

    private const KEY_CONTEXT = 'context';

    /**
     * Create a new DebugLogHook instance.
     *
     * @param  string  $logFile  Absolute path to the log file. Created on first write.
     * @param  string  $eventName  Optional label written to every line for this binding.
     * @return void
     */
    public function __construct(
        private readonly string $logFile,
        private readonly string $eventName = self::DEFAULT_EVENT_LABEL,
    ) {}

    /**
     * Write a single JSON line for the event; encoding failures are silently dropped.
     *
     * @param  array<string, mixed>  $context  Event data from the hook dispatcher.
     * @return void
     */
    public function handle(array $context): void
    {
        $line = json_encode([
            self::KEY_TIMESTAMP => (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            self::KEY_EVENT => $this->eventName,
            self::KEY_CONTEXT => $context,
        ], self::JSON_FLAGS);

        if ($line === false) {
            return;
        }

        @file_put_contents($this->logFile, $line.self::LINE_TERMINATOR, FILE_APPEND | LOCK_EX);
    }

    /**
     * Path to the log file: exposed for tests and operators.
     *
     * @return string
     */
    public function logFile(): string
    {
        return $this->logFile;
    }

    /**
     * Event name label used in every line written by this binding.
     *
     * @return string
     */
    public function eventName(): string
    {
        return $this->eventName;
    }
}
