<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tools;

use PhpClaw\Exceptions\ToolException;

/**
 * Encodes tool output as JSON with row-boundary truncation at a byte limit.
 */
final class ToolOutputEncoder
{
    public const MAX_OUTPUT_BYTES = 8192;

    /**
     * JSON-encode $data with byte-boundary truncation on a named list key.
     *
     * @param  array<string, mixed>  $data  Full result payload.
     * @param  string  $listKey  Key inside $data that holds the row array.
     * @param  int  $maxBytes  Maximum JSON byte size before truncation.
     * @param  string  $toolName  Tool name used in the ToolException message.
     * @return string
     *
     * @throws ToolException If json_encode fails.
     */
    public static function truncate(
        array $data,
        string $listKey,
        int $maxBytes,
        string $toolName,
    ): string {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ToolException("{$toolName}: failed to JSON-encode results.");
        }

        if (strlen($json) <= $maxBytes) {
            return $json;
        }

        $items = $data[$listKey] ?? [];
        $kept = [];
        $bytes = 0;

        foreach ($items as $item) {
            $encoded = json_encode($item, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) > $maxBytes) {
                break;
            }

            $kept[] = $item;
            $bytes += strlen($encoded);
        }

        $data[$listKey] = $kept;
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            throw new ToolException("{$toolName}: failed to JSON-encode results.");
        }

        return $json."\n[... output truncated: ".count($items).' total rows, '.count($kept).' shown ...]';
    }
}
