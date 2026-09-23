<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

/**
 * Shared JSON result encoding for data tools.
 */
trait JsonToolResult
{
    /**
     * Truncation cap, in bytes, for encoded JSON output.
     *
     * @return int
     */
    private static function maxBytes(): int
    {
        return OutputByteCap::MAX_OUTPUT_BYTES;
    }

    /**
     * Tool identifier used to prefix the encode-failure message.
     *
     * @return string
     */
    abstract public function name(): string;

    /**
     * Cap a list of rows against the byte budget at element boundaries, for tools on the execution contract.
     *
     * @param  list<mixed>  $rows  Rows to fit within the byte budget.
     * @return array{rows: list<mixed>, total: int, shown: int, truncated: bool}
     */
    protected function capRows(array $rows): array
    {
        $kept = [];
        $bytes = 0;

        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                continue;
            }

            if ($bytes + strlen($encoded) > self::maxBytes()) {
                break;
            }

            $kept[] = $row;
            $bytes += strlen($encoded);
        }

        return [
            'rows' => $kept,
            'total' => count($rows),
            'shown' => count($kept),
            'truncated' => count($kept) < count($rows),
        ];
    }
}
