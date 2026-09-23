<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\PrestaShop\Tools\ToolOutputEncoder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolOutputEncoder::class)]
final class ToolOutputEncoderTest extends TestCase
{
    public function test_a_small_list_is_kept_whole(): void
    {
        $rows = [['id' => 1], ['id' => 2]];

        $capped = ToolOutputEncoder::cap($rows);

        self::assertSame($rows, $capped['rows']);
        self::assertSame(2, $capped['total']);
        self::assertSame(2, $capped['shown']);
        self::assertFalse($capped['truncated']);
    }

    public function test_a_list_over_the_budget_is_capped_at_a_row_boundary(): void
    {
        $rows = array_fill(0, 100, ['val' => str_repeat('x', 200)]);

        $capped = ToolOutputEncoder::cap($rows, 1024);

        self::assertTrue($capped['truncated']);
        self::assertSame(100, $capped['total']);
        self::assertLessThan(100, $capped['shown']);
        self::assertSame($capped['shown'], count($capped['rows']));

        foreach ($capped['rows'] as $row) {
            self::assertSame(['val' => str_repeat('x', 200)], $row);
        }
    }

    public function test_the_kept_rows_stay_inside_the_budget(): void
    {
        $rows = array_fill(0, 100, ['val' => str_repeat('x', 200)]);

        $capped = ToolOutputEncoder::cap($rows, 1024);

        self::assertLessThanOrEqual(
            1024,
            strlen((string) json_encode($capped['rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        );
    }

    public function test_an_untruncated_result_carries_no_warning(): void
    {
        self::assertSame([], ToolOutputEncoder::warnings(ToolOutputEncoder::cap([['id' => 1]])));
    }

    public function test_a_truncated_result_carries_a_warning_that_names_both_counts(): void
    {
        $capped = ToolOutputEncoder::cap(array_fill(0, 100, ['val' => str_repeat('x', 200)]), 1024);

        $warnings = ToolOutputEncoder::warnings($capped);

        self::assertCount(1, $warnings);
        self::assertSame('OUTPUT_TRUNCATED', $warnings[0]['code']);
        self::assertStringContainsString((string) $capped['shown'], $warnings[0]['message']);
        self::assertStringContainsString('100', $warnings[0]['message']);
    }

    public function test_a_row_that_cannot_be_encoded_is_skipped_rather_than_failing(): void
    {
        $capped = ToolOutputEncoder::cap([['ok' => 1], ['bad' => "\xB1\x31"], ['ok' => 2]]);

        self::assertSame([['ok' => 1], ['ok' => 2]], $capped['rows']);
    }

    public function test_the_row_budget_leaves_room_for_the_envelope(): void
    {
        self::assertLessThan(
            ToolOutputEncoder::MAX_OUTPUT_BYTES,
            ToolOutputEncoder::MAX_ROW_BYTES,
        );
    }
}
