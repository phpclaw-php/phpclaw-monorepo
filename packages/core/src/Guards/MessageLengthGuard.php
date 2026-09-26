<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\PromptOnlyGuardInterface;
use PhpClaw\Guards\Contracts\RawInputGuardInterface;

/**
 * Rejects messages that exceed a configurable character limit.
 */
#[Guard(priority: 0, name: 'message_length', label: 'Message Length', enabledByDefault: true, since: '1.0.0')]
final class MessageLengthGuard implements PromptOnlyGuardInterface, RawInputGuardInterface
{
    private const DEFAULT_MAX_LENGTH = 40000;

    /**
     * Create a new MessageLengthGuard instance.
     *
     * @param  int  $maxLength  Maximum allowed character count.
     * @return void
     */
    public function __construct(
        private readonly int $maxLength = self::DEFAULT_MAX_LENGTH,
    ) {}

    /**
     * Scan the message against the configured maximum length.
     *
     * @param  string  $message  The user message to scan.
     * @return void
     *
     * @throws GuardException If the message exceeds the configured limit.
     */
    public function scan(string $message): void
    {
        if (mb_strlen($message) > $this->maxLength) {
            throw new GuardException(
                "Message exceeds the maximum allowed length of {$this->maxLength} characters.",
                guardClass: self::class,
            );
        }
    }
}
