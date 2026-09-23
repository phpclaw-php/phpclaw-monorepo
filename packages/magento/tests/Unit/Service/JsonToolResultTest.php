<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Magento\Service\JsonToolResult;
use PhpClaw\Magento\Service\OutputByteCap;
use PHPUnit\Framework\TestCase;

final class JsonToolResultTest extends TestCase
{
    private object $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class
        {
            use JsonToolResult;

            public function name(): string
            {
                return 'demo_tool';
            }

            public function cap(array $rows): array
            {
                return $this->capRows($rows);
            }
        };
    }

    public function test_a_short_list_is_returned_whole_and_unflagged(): void
    {
        $rows = [['id' => 1], ['id' => 2], ['id' => 3]];

        $capped = $this->subject->cap($rows);

        self::assertSame($rows, $capped['rows']);
        self::assertSame(3, $capped['total']);
        self::assertSame(3, $capped['shown']);
        self::assertFalse($capped['truncated']);
    }

    public function test_an_empty_list_reports_zero_and_is_not_truncated(): void
    {
        $capped = $this->subject->cap([]);

        self::assertSame([], $capped['rows']);
        self::assertSame(0, $capped['total']);
        self::assertSame(0, $capped['shown']);
        self::assertFalse($capped['truncated']);
    }

    public function test_a_list_over_the_byte_budget_is_cut_at_a_row_boundary(): void
    {
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['id' => $i, 'blob' => str_repeat('x', 80)];
        }

        $capped = $this->subject->cap($rows);

        self::assertTrue($capped['truncated']);
        self::assertSame(500, $capped['total']);
        self::assertLessThan(500, $capped['shown']);
        self::assertCount($capped['shown'], $capped['rows']);
        self::assertSame($rows[0], $capped['rows'][0], 'rows are kept in order from the start');
    }

    public function test_the_kept_rows_stay_within_the_byte_budget(): void
    {
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['id' => $i, 'blob' => str_repeat('y', 80)];
        }

        $capped = $this->subject->cap($rows);

        $bytes = 0;
        foreach ($capped['rows'] as $row) {
            $bytes += strlen((string) json_encode($row, JSON_UNESCAPED_UNICODE));
        }

        self::assertLessThanOrEqual(OutputByteCap::MAX_OUTPUT_BYTES, $bytes);
    }

    public function test_a_single_row_larger_than_the_budget_keeps_nothing(): void
    {
        $capped = $this->subject->cap([['blob' => str_repeat('z', OutputByteCap::MAX_OUTPUT_BYTES + 100)]]);

        self::assertSame([], $capped['rows']);
        self::assertSame(1, $capped['total']);
        self::assertSame(0, $capped['shown']);
        self::assertTrue($capped['truncated']);
    }

    public function test_a_row_that_cannot_be_encoded_is_skipped_rather_than_fatal(): void
    {
        $capped = $this->subject->cap([['ok' => 1], ['bad' => "\xB1\x31"], ['ok' => 2]]);

        self::assertSame(3, $capped['total']);
        self::assertSame(2, $capped['shown']);
        self::assertTrue($capped['truncated']);
    }
}
