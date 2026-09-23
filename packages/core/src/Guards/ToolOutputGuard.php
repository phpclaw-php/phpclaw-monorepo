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
        'you are now',
        'act as if',
        'pretend you are',
        '<?php',
        '<?=',
        '?>',
    ];

    /**
     * Scan and redact injection patterns from a tool result. Homoglyph-substituted patterns are detected (the audit hook fires) but redact() cannot yet neutralise them in the returned text, a tracked follow-up, not silently left open.
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
