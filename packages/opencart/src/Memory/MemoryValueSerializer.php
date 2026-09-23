<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Memory;

/**
 * Encodes and decodes PHP values for storage in OpenCart memory table columns.
 */
final class MemoryValueSerializer
{
    /**
     * Encode a value for storage.
     *
     * @param  mixed  $value
     * @return string
     */
    public static function encode(mixed $value): string
    {
        return is_string($value) ? $value : serialize($value);
    }

    /**
     * Decode a stored value back to its original PHP type.
     *
     * @param  string  $stored
     * @return mixed
     */
    public static function decode(string $stored): mixed
    {
        if ($stored !== '' && (
            str_starts_with($stored, 'a:')
            || str_starts_with($stored, 'O:')
            || str_starts_with($stored, 's:')
            || str_starts_with($stored, 'i:')
            || str_starts_with($stored, 'b:')
        )) {
            $result = unserialize($stored, ['allowed_classes' => false]);
            if ($result !== false) {
                return $result;
            }
        }

        return $stored;
    }
}
