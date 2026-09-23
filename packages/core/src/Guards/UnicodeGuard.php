<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\AutoDiscovery\Attributes\Guard;
use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\GuardInterface;

/**
 * Blocks Unicode-based injection attacks: bidirectional overrides and messages consisting entirely of invisible / zero-width characters.
 */
#[Guard(priority: 2, name: 'unicode', label: 'Unicode Injection', enabledByDefault: true, since: '1.0.0')]
final class UnicodeGuard implements GuardInterface
{
    private const BIDI_OVERRIDES = [
        "\u{202A}",
        "\u{202B}",
        "\u{202C}",
        "\u{202D}",
        "\u{202E}",
        "\u{2066}",
        "\u{2067}",
        "\u{2068}",
        "\u{2069}",
    ];

    private const ZERO_WIDTH = [
        "\u{200B}",
        "\u{200C}",
        "\u{200D}",
        "\u{FEFF}",
        "\u{00AD}",
        "\u{2060}",
        "\u{180E}",
    ];

    private const HIDDEN_SEPARATORS =
        '\x{200B}\x{200E}\x{200F}\x{061C}\x{2060}\x{FEFF}\x{00AD}\x{180E}\x{034F}';

    private const ERROR_BIDI_OVERRIDE = 'Prompt injection detected: message contains Unicode bidirectional override characters.';

    private const ERROR_INVISIBLE_ONLY = 'Prompt injection detected: message consists entirely of invisible Unicode characters.';

    private const ERROR_HIDDEN_SEPARATOR = 'Prompt injection detected: message contains hidden Unicode separator characters between visible text.';

    /**
     * Scan the message for Unicode-based injection attacks.
     *
     * @param  string  $message  The user message to scan.
     * @return void
     *
     * @throws GuardException When a bidi override is present, a hidden separator is embedded between visible chars, or the message is non-empty but contains only invisible characters.
     */
    public function scan(string $message): void
    {
        if (self::containsBidiOverride($message)) {
            throw new GuardException(self::ERROR_BIDI_OVERRIDE, guardClass: self::class);
        }

        if (self::containsEmbeddedSeparator($message)) {
            throw new GuardException(self::ERROR_HIDDEN_SEPARATOR, guardClass: self::class);
        }

        if ($message !== '' && self::isOnlyInvisible($message)) {
            throw new GuardException(self::ERROR_INVISIBLE_ONLY, guardClass: self::class);
        }
    }

    /**
     * True when a hidden separator character appears between two visible (non-whitespace) characters.
     *
     * @param  string  $message  The text to inspect.
     * @return bool
     */
    private static function containsEmbeddedSeparator(string $message): bool
    {
        return preg_match('/\S['.self::HIDDEN_SEPARATORS.']+\S/u', $message) === 1;
    }

    /**
     * True when any bidirectional control character is present.
     *
     * @param  string  $message  The text to inspect.
     * @return bool
     */
    private static function containsBidiOverride(string $message): bool
    {
        foreach (self::BIDI_OVERRIDES as $char) {
            if (str_contains($message, $char)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when removing every zero-width character leaves only whitespace.
     *
     * @param  string  $message  The text to inspect.
     * @return bool
     */
    private static function isOnlyInvisible(string $message): bool
    {
        $stripped = str_replace(self::ZERO_WIDTH, '', $message);

        return trim($stripped) === '';
    }
}
