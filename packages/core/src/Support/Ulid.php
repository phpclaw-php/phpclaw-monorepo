<?php

declare(strict_types=1);

namespace PhpClaw\Support;

/**
 * Minimal ULID generator, 26-character, time-sortable, URL-safe unique identifier.
 */
final class Ulid
{
    private const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const LENGTH = 26;

    private const TIMESTAMP_LENGTH = 10;

    private const RANDOM_LENGTH = 16;

    private const BASE32_MASK = 0x1F;

    private const BASE32_BITS_PER_CHAR = 5;

    private const ENCODING_MAX_INDEX = 31;

    private const MS_PER_SECOND = 1000;

    private static int $lastMs = 0;

    private static string $lastRandom = '';

    /**
     * Prevent instantiation: this is a pure static utility.
     *
     * @return void
     */
    private function __construct() {}

    /**
     * Generate a new ULID string. Monotonic within the same millisecond.
     *
     * @return string
     */
    public static function generate(): string
    {
        $ms = (int) (microtime(true) * self::MS_PER_SECOND);

        if ($ms === self::$lastMs && self::$lastRandom !== '') {
            self::$lastRandom = self::incrementRandom(self::$lastRandom);
        } else {
            self::$lastMs = $ms;
            self::$lastRandom = self::encodeRandom();
        }

        return self::encodeMs($ms).self::$lastRandom;
    }

    /**
     * Structural validity check: exactly 26 chars, all in the Crockford Base32 alphabet.
     *
     * @param  string  $candidate  String to validate.
     * @return bool
     */
    public static function isValid(string $candidate): bool
    {
        if (strlen($candidate) !== self::LENGTH) {
            return false;
        }

        return strspn($candidate, self::ENCODING) === self::LENGTH;
    }

    /**
     * Encode the given millisecond timestamp as 10 Base32 characters (big-endian).
     *
     * @param  int  $ms  Millisecond timestamp.
     * @return string
     */
    private static function encodeMs(int $ms): string
    {
        $enc = '';

        for ($i = self::TIMESTAMP_LENGTH - 1; $i >= 0; $i--) {
            $enc = self::ENCODING[$ms & self::BASE32_MASK].$enc;
            $ms >>= self::BASE32_BITS_PER_CHAR;
        }

        return $enc;
    }

    /**
     * Increment a 16-char Crockford Base32 string by 1 with carry.
     *
     * @param  string  $random  Random component bytes.
     * @return string
     */
    private static function incrementRandom(string $random): string
    {
        $chars = str_split($random);
        $alphabet = self::ENCODING;

        for ($i = self::RANDOM_LENGTH - 1; $i >= 0; $i--) {
            $pos = strpos($alphabet, $chars[$i]);
            if ($pos === false) {
                return self::encodeRandom();
            }
            if ($pos < self::ENCODING_MAX_INDEX) {
                $chars[$i] = self::ENCODING[$pos + 1];

                return implode('', $chars);
            }
            $chars[$i] = self::ENCODING[0];
        }

        return self::encodeRandom();
    }

    /**
     * Encode 80 bits of cryptographic randomness as 16 Base32 characters.
     *
     * @return string
     */
    private static function encodeRandom(): string
    {
        $enc = '';

        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $enc .= self::ENCODING[random_int(0, self::ENCODING_MAX_INDEX)];
        }

        return $enc;
    }
}
