<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Magento\Service\OutputByteCap;
use PHPUnit\Framework\TestCase;

final class OutputByteCapTest extends TestCase
{
    public function test_cap_is_eight_kilobytes(): void
    {
        self::assertSame(8192, OutputByteCap::MAX_OUTPUT_BYTES);
    }

    public function test_truncation_notice_reports_the_cap_in_kilobytes(): void
    {
        self::assertSame("\n[... output truncated at 8 KB ...]", OutputByteCap::truncationNotice());
    }

    public function test_text_at_the_cap_is_returned_unchanged(): void
    {
        $text = str_repeat('a', OutputByteCap::MAX_OUTPUT_BYTES);

        self::assertSame($text, OutputByteCap::truncate($text));
    }

    public function test_text_over_the_cap_is_cut_and_gets_the_notice(): void
    {
        $result = OutputByteCap::truncate(str_repeat('a', OutputByteCap::MAX_OUTPUT_BYTES + 1));

        self::assertSame(
            str_repeat('a', OutputByteCap::MAX_OUTPUT_BYTES).OutputByteCap::truncationNotice(),
            $result,
        );
    }

    public function test_empty_text_is_returned_unchanged(): void
    {
        self::assertSame('', OutputByteCap::truncate(''));
    }
}
