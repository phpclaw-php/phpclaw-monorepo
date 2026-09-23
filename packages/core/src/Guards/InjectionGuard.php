<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Concerns\NormalisesText;
use PhpClaw\Guards\Contracts\GuardInterface;

/**
 * Blocks direct prompt-injection patterns: "ignore previous instructions", "you are now", etc.
 */
#[Guard(priority: 1, name: 'injection', label: 'Prompt Injection', enabledByDefault: true, since: '1.0.0')]
final class InjectionGuard implements GuardInterface
{
    use NormalisesText;

    private const PATTERNS = [
        'ignore previous instructions',
        'ignore all instructions',
        'disregard your',
        'forget your',
        'system prompt',
        'jailbreak',
        'override instructions',
        'as a developer mode',
        'dan mode',
        'do anything now',
        'new persona',
    ];

    private const ERROR_PATTERN = "Prompt injection detected: message contains blocked pattern '%s'.";

    /**
     * Scan the message for prompt-injection patterns.
     *
     * @param  string  $message  The user message to scan.
     * @return void
     *
     * @throws GuardException If any injection pattern is found.
     */
    public function scan(string $message): void
    {
        self::assertNoMatch($this->normalise($message), self::PATTERNS, self::ERROR_PATTERN);
    }
}
