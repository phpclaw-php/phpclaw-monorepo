<?php

declare(strict_types=1);

namespace PhpClaw\Guards\Contracts;

use PhpClaw\Exceptions\GuardException;

/**
 * Contract for all prompt-injection guard implementations. Frozen until v2.0.
 */
interface GuardInterface
{
    /**
     * Scan the message for problematic content.
     *
     * @param  string  $message  The user message to inspect.
     * @return void
     *
     * @throws GuardException If this guard detects a violation.
     */
    public function scan(string $message): void;
}
