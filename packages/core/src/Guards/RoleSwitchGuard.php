<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Concerns\NormalisesText;
use PhpClaw\Guards\Contracts\GuardInterface;

/**
 * Blocks persona and role-redefinition injection patterns: "act as", "pretend you are", etc.
 */
#[Guard(priority: 4, name: 'role_switch', label: 'Role Switch', enabledByDefault: true, since: '1.0.0')]
final class RoleSwitchGuard implements GuardInterface
{
    use NormalisesText;

    public const PATTERNS = [
        'you are now',
        'act as if',
        'pretend you are',
    ];

    private const ERROR_PATTERN = "Prompt injection detected: message contains role-switch pattern '%s'.";

    /**
     * Scan the message for role-switch injection attempts.
     *
     * @param  string  $message  The user message to scan.
     * @return void
     *
     * @throws GuardException If any role-switch pattern is found.
     */
    public function scan(string $message): void
    {
        self::assertNoMatch($this->normalise($message), self::PATTERNS, self::ERROR_PATTERN);
    }
}
