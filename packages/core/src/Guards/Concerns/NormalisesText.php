<?php

declare(strict_types=1);

namespace PhpClaw\Guards\Concerns;

use PhpClaw\Exceptions\GuardException;

/**
 * Normalises prompt text: folds compatibility forms, strips invisible chars, lowercases, maps homoglyphs, collapses whitespace, for guard matching.
 */
trait NormalisesText
{
    /**
     * Homoglyph map: visually similar Unicode chars → ASCII equivalent.
     *
     * @return array<string, string>
     */
    private static function homoglyphMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [
                'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c',
                'х' => 'x', 'у' => 'y', 'і' => 'i', 'ј' => 'j', 'ѕ' => 's',
                'α' => 'a', 'β' => 'b', 'ε' => 'e', 'ο' => 'o', 'ρ' => 'p',
                'τ' => 't', 'υ' => 'u', 'χ' => 'x',
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
                'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
                'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
                'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
                'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
                'ý' => 'y', 'ÿ' => 'y',
                'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
                "\u{200B}" => '', "\u{200C}" => '', "\u{200D}" => '',
                "\u{FEFF}" => '', "\u{00AD}" => '', "\u{2060}" => '',
                "\u{180E}" => '',
            ];
        }

        return $map;
    }

    /**
     * Normalise prompt text: NFKC-fold, strip invisible chars, lowercase, map homoglyphs, then collapse whitespace.
     *
     * @param  string  $message  Raw prompt text to normalise.
     * @return string
     */
    private function normalise(string $message): string
    {
        if (class_exists(\Normalizer::class)) {
            $folded = \Normalizer::normalize($message, \Normalizer::FORM_KC);
            if ($folded !== false) {
                $message = $folded;
            }
        } else {
            $message = self::foldFullwidthAscii($message);
        }

        $stripped = preg_replace('/[\p{Cf}\x{034F}\x{FE00}-\x{FE0F}]/u', '', $message);
        if ($stripped !== null) {
            $message = $stripped;
        }

        $message = strtr(mb_strtolower($message), self::homoglyphMap());

        $collapsed = preg_replace('/\s+/u', ' ', $message);
        if ($collapsed !== null) {
            $message = $collapsed;
        }

        return trim($message);
    }

    /**
     * Fold fullwidth ASCII characters (U+FF01–FF5E) down to their ASCII equivalents by subtracting U+FEE0.
     *
     * @param  string  $message  The text to fold.
     * @return string
     */
    private static function foldFullwidthAscii(string $message): string
    {
        $result = preg_replace_callback(
            '/[\x{FF01}-\x{FF5E}]/u',
            static function (array $matches): string {
                $bytes = unpack('C3', $matches[0]);
                $codepoint = (($bytes[1] & 0x0F) << 12)
                    | (($bytes[2] & 0x3F) << 6)
                    | ($bytes[3] & 0x3F);

                return chr($codepoint - 0xFEE0);
            },
            $message
        );

        return $result !== null ? $result : $message;
    }

    /**
     * Throw GuardException with the first pattern that appears in $haystack.
     *
     * @param  string  $haystack  Text to search.
     * @param  string[]  $patterns  List of substrings that are forbidden.
     * @param  string  $errorTemplate  printf format with one `%s` placeholder.
     * @return void
     *
     * @throws GuardException When any pattern is found in the haystack.
     */
    private static function assertNoMatch(string $haystack, array $patterns, string $errorTemplate): void
    {
        foreach ($patterns as $pattern) {
            if (str_contains($haystack, $pattern)) {
                throw new GuardException(sprintf($errorTemplate, $pattern), guardClass: self::class);
            }
        }
    }
}
