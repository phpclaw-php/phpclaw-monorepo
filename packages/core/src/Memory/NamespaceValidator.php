<?php

declare(strict_types=1);

namespace PhpClaw\Memory;

use PhpClaw\Exceptions\MemoryException;

/**
 * Validates memory namespace strings: blocks path traversal, null bytes, and invalid characters.
 */
final class NamespaceValidator
{
    private const MAX_LENGTH = 100;

    /**
     * Validate a namespace string.
     *
     * @param  string  $namespace  Namespace to validate.
     * @return void
     *
     * @throws MemoryException If the namespace violates any rule.
     */
    public static function validate(string $namespace): void
    {
        if ($namespace === '') {
            throw new MemoryException('Memory namespace must not be empty.');
        }

        if (mb_strlen($namespace) > self::MAX_LENGTH) {
            throw new MemoryException(
                'Memory namespace must not exceed '.self::MAX_LENGTH.' characters.'
            );
        }

        if (str_contains($namespace, "\0")) {
            throw new MemoryException(
                'Memory namespace contains illegal null byte.'
            );
        }

        if (str_contains($namespace, '..')) {
            throw new MemoryException(
                "Memory namespace contains illegal path traversal sequence '..'."
            );
        }

        if (str_contains($namespace, '/') || str_contains($namespace, '\\')) {
            throw new MemoryException(
                "Memory namespace must not contain path separators ('/' or '\\')."
            );
        }

        if (preg_match('/[^a-zA-Z0-9_\-\.:]/', $namespace) === 1) {
            throw new MemoryException(
                "Memory namespace '{$namespace}' contains illegal characters."
                .' Allowed: a-z A-Z 0-9 _ - . :'
            );
        }
    }
}
