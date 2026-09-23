<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Magento\Service\CsvList;
use PHPUnit\Framework\TestCase;

final class CsvListTest extends TestCase
{
    public function test_parse_returns_empty_array_for_empty_string(): void
    {
        self::assertSame([], CsvList::parse(''));
    }

    public function test_parse_splits_comma_separated_values(): void
    {
        self::assertSame(['hooks', 'guards', 'tools'], CsvList::parse('hooks,guards,tools'));
    }

    public function test_parse_trims_whitespace_around_values(): void
    {
        self::assertSame(['hooks', 'guards'], CsvList::parse(' hooks , guards '));
    }

    public function test_parse_filters_empty_segments(): void
    {
        self::assertSame(['hooks', 'guards'], CsvList::parse('hooks,,guards,'));
    }

    public function test_parse_single_value(): void
    {
        self::assertSame(['anthropic'], CsvList::parse('anthropic'));
    }
}
