<?php

declare(strict_types=1);

namespace PhpClaw\Guards;

use PhpClaw\Guards\Concerns\NormalisesText;
use PhpClaw\Hooks\HookDispatcher;

/**
 * Sanitises tool output before it is appended to agent history, blocking indirect prompt injection.
 */
final class ToolOutputGuard
{
    use NormalisesText;

    private const REDACTION_MARKER = '[REDACTED]';

    private const INVISIBLE_CHARS_PATTERN = '/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00AD}\x{2060}\x{180E}]/u';

    private const PATTERNS = [...InjectionGuard::PATTERNS, ...RoleSwitchGuard::PATTERNS, '<?php', '<?=', '?>'];

    /**
     * Scan and redact injection patterns from a tool result; homoglyph-substituted patterns trigger the audit hook but are not yet neutralised in the returned text.
     *
     * @param  string  $toolResult  Raw output returned by the tool.
     * @param  string  $toolName  Name of the tool that produced the result (for the hook).
     * @return string The sanitised tool result with patterns replaced by [REDACTED].
     */
    public function sanitise(string $toolResult, string $toolName = ''): string
    {
        $toolResult = self::stripInvisible($toolResult);
        $normalised = $this->normalise($toolResult);

        foreach (self::PATTERNS as $pattern) {
            if (! str_contains($normalised, $pattern)) {
                continue;
            }

            HookDispatcher::guardToolOutputRedacted($toolName, $pattern);

            $toolResult = self::redact($toolResult, $pattern);
            $normalised = $this->normalise($toolResult);
        }

        return $toolResult;
    }

    /**
     * Strip invisible/zero-width characters from the real output, visible characters are never rewritten, unlike normalise() which also homoglyph-maps and lowercases.
     *
     * @param  string  $text  Text to strip.
     * @return string
     */
    private static function stripInvisible(string $text): string
    {
        return (string) preg_replace(self::INVISIBLE_CHARS_PATTERN, '', $text);
    }

    /**
     * Case-insensitively replace every occurrence of $pattern in $text with the redaction marker.
     *
     * @param  string  $text  Text to redact within.
     * @param  string  $pattern  Substring to replace (matched case-insensitively).
     * @return string
     */
    private static function redact(string $text, string $pattern): string
    {
        return (string) preg_replace(
            '/'.preg_quote($pattern, '/').'/i',
            self::REDACTION_MARKER,
            $text,
        );
    }
}
