<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tools;

/**
 * Caps a tool's row list at a byte budget so the response envelope built around it stays
 * inside the output limit and stays decodable.
 */
final class ToolOutputEncoder
{
    public const MAX_OUTPUT_BYTES = 8192;

    public const MAX_ROW_BYTES = 6144;

    /**
     * Keep as many leading rows as fit the budget and report what was left behind.
     *
     * @param  array<int, mixed>  $rows  Full row list.
     * @param  int  $maxBytes  Byte budget for the encoded rows alone.
     * @return array{rows: array<int, mixed>, total: int, shown: int, truncated: bool}
     */
    public static function cap(array $rows, int $maxBytes = self::MAX_ROW_BYTES): array
    {
        $kept = [];
        $bytes = 0;
        $truncated = false;

        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) + 1 > $maxBytes) {
                $truncated = true;
                break;
            }

            $kept[] = $row;
            $bytes += strlen($encoded) + 1;
        }

        return [
            'rows' => $kept,
            'total' => count($rows),
            'shown' => count($kept),
            'truncated' => $truncated,
        ];
    }

    /**
     * Build the truncation warning a capped response carries inside its envelope.
     *
     * @param  array{rows: array<int, mixed>, total: int, shown: int, truncated: bool}  $capped  A cap() result.
     * @return array<int, array{code: string, message: string}>
     */
    public static function warnings(array $capped): array
    {
        if (! $capped['truncated']) {
            return [];
        }

        return [[
            'code' => 'OUTPUT_TRUNCATED',
            'message' => sprintf(
                'Output was capped at %d bytes: %d of %d rows are shown. Narrow the filters or page '
                .'with offset to see the rest.',
                self::MAX_ROW_BYTES,
                $capped['shown'],
                $capped['total'],
            ),
        ]];
    }
}
