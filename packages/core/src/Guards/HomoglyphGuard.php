<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Concerns\NormalisesText;
use PhpClaw\Guards\Contracts\GuardInterface;

/**
 * Blocks injection patterns disguised via homoglyph substitution (Cyrillic, Greek, Latin extended lookalikes).
 */
#[Guard(priority: 3, name: 'homoglyph', label: 'Homoglyph Substitution', enabledByDefault: true, since: '1.0.0')]
final class HomoglyphGuard implements GuardInterface
{
    use NormalisesText;

    private const PATTERNS = [...InjectionGuard::PATTERNS, ...RoleSwitchGuard::PATTERNS];

    private const ERROR_PATTERN = "Prompt injection detected: homoglyph-substituted pattern '%s' found.";

    /**
     * Scan the message for homoglyph (look-alike Unicode) obfuscation.
     *
     * @param  string  $message  The user message to scan.
     * @return void
     *
     * @throws GuardException If any pattern is found after homoglyph normalisation.
     */
    public function scan(string $message): void
    {
        $normalised = $this->normalise($message);
        $plainBaseline = trim(mb_strtolower($message));
        if ($normalised === $plainBaseline) {
            return;
        }

        self::assertNoMatch($normalised, self::PATTERNS, self::ERROR_PATTERN);
    }
}
