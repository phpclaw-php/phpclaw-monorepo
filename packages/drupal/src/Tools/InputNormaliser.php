<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tools;

/**
 * Normalises LLM tool input arrays before processing.
 */
final class InputNormaliser
{
    /**
     * Unwrap single-element arrays to their scalar value.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function flattenArrayValues(array $input): array
    {
        return array_map(static fn (mixed $v): mixed => is_array($v) ? ($v[0] ?? '') : $v, $input);
    }
}
