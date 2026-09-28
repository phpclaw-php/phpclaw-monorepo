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
     * Redact every injection pattern found in a tool result, including look-alike, full-width and extra-space spellings, and leave every other byte unchanged.
     *
     * @param  string  $toolResult  Raw output returned by the tool.
     * @param  string  $toolName  Name of the tool that produced the result (for the hook).
     * @return string The sanitised tool result with patterns replaced by [REDACTED].
     */
    public function sanitise(string $toolResult, string $toolName = ''): string
    {
        $toolResult = self::stripInvisible($toolResult);
        $normalised = $this->normalise($toolResult);
        $found = array_values(array_filter(self::PATTERNS, static fn (string $pattern): bool => str_contains($normalised, $pattern)));

        if ($found === []) {
            return $toolResult;
        }

        [$folded, $starts] = $this->foldWithOrigin($toolResult);
        $ranges = [];

        foreach ($found as $pattern) {
            HookDispatcher::guardToolOutputRedacted($toolName, $pattern);
            array_push($ranges, ...self::originRangesOf($pattern, $folded, $starts, $toolResult));
        }

        return self::replaceRanges($toolResult, $ranges);
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
     * Fold the text one character at a time the way normalise() does, recording for every folded byte the start byte of the original character it came from.
     *
     * @param  string  $text  Text to fold.
     * @return array{0: string, 1: list<int>} The folded text, and the original start byte of each folded byte.
     */
    private function foldWithOrigin(string $text): array
    {
        $folded = '';
        $starts = [];
        $length = strlen($text);
        $offset = 0;

        while ($offset < $length) {
            $size = self::utf8CharacterSize(ord($text[$offset]));
            $character = substr($text, $offset, $size);
            $piece = $size === 1 ? self::foldAscii($character) : self::foldCharacter($character);

            if ($piece !== '' && ! ($piece === ' ' && ($folded === '' || str_ends_with($folded, ' ')))) {
                $folded .= $piece;
                array_push($starts, ...array_fill(0, strlen($piece), $offset));
            }

            $offset += $size;
        }

        return [$folded, $starts];
    }

    /**
     * Byte length of a UTF-8 character from its first byte (1 for ASCII or a stray continuation byte).
     *
     * @param  int  $leadByte  First byte of the character.
     * @return int
     */
    private static function utf8CharacterSize(int $leadByte): int
    {
        return match (true) {
            $leadByte >= 0xF0 => 4,
            $leadByte >= 0xE0 => 3,
            $leadByte >= 0xC0 => 2,
            default => 1,
        };
    }

    /**
     * Fold one single-byte character: whitespace becomes one space, letters are lower-cased.
     *
     * @param  string  $character  One byte.
     * @return string
     */
    private static function foldAscii(string $character): string
    {
        return ctype_space($character) ? ' ' : strtolower($character);
    }

    /**
     * Fold one character: NFKC (or full-width folding without intl), drop format and combining marks, lower-case, map look-alikes, and reduce whitespace to one space.
     *
     * @param  string  $character  One UTF-8 character.
     * @return string The folded form, possibly empty or several bytes long.
     */
    private static function foldCharacter(string $character): string
    {
        if (class_exists(\Normalizer::class)) {
            $character = (string) (\Normalizer::normalize($character, \Normalizer::FORM_KC) ?: $character);
        } else {
            $character = self::foldFullwidthAscii($character);
        }

        $character = (string) preg_replace('/[\p{Cf}\p{Mn}\x{034F}\x{FE00}-\x{FE0F}]/u', '', $character);

        if (preg_match('/^\s+$/u', $character) === 1) {
            return ' ';
        }

        return strtr(mb_strtolower($character), self::homoglyphMap());
    }

    /**
     * Every occurrence of a pattern in the folded text, as byte ranges of the original text.
     *
     * @param  string  $pattern  Lower-case pattern to find.
     * @param  string  $folded  Folded text.
     * @param  list<int>  $starts  Original start byte of each folded byte.
     * @param  string  $original  Original text, to find where the last matched character ends.
     * @return list<array{0: int, 1: int}>
     */
    private static function originRangesOf(string $pattern, string $folded, array $starts, string $original): array
    {
        $ranges = [];
        $offset = 0;

        while (($at = strpos($folded, $pattern, $offset)) !== false) {
            $last = $at + strlen($pattern) - 1;
            $ranges[] = [$starts[$at], $starts[$last] + self::utf8CharacterSize(ord($original[$starts[$last]]))];
            $offset = $last + 1;
        }

        return $ranges;
    }

    /**
     * Replace each original byte range with the redaction marker, merging overlapping ranges, so every byte outside a range is kept as it was.
     *
     * @param  string  $text  Original text.
     * @param  list<array{0: int, 1: int}>  $ranges  Byte ranges to redact.
     * @return string
     */
    private static function replaceRanges(string $text, array $ranges): string
    {
        if ($ranges === []) {
            return $text;
        }

        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [array_shift($ranges)];

        foreach ($ranges as [$start, $end]) {
            $lastIndex = count($merged) - 1;

            if ($start <= $merged[$lastIndex][1]) {
                $merged[$lastIndex][1] = max($merged[$lastIndex][1], $end);

                continue;
            }

            $merged[] = [$start, $end];
        }

        foreach (array_reverse($merged) as [$start, $end]) {
            $text = substr_replace($text, self::REDACTION_MARKER, $start, $end - $start);
        }

        return $text;
    }
}
