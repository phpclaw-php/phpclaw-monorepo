<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Support;

use PhpClaw\Drupal\Support\CloudDisableParser;
use PHPUnit\Framework\TestCase;

final class CloudDisableParserTest extends TestCase
{
    public function test_parse_returns_empty_array_for_empty_string(): void
    {
        $this->assertSame([], CloudDisableParser::parse(''));
    }

    public function test_parse_returns_empty_array_for_empty_array(): void
    {
        $this->assertSame([], CloudDisableParser::parse([]));
    }

    public function test_parse_splits_comma_separated_string(): void
    {
        $result = CloudDisableParser::parse('guards, webhooks');
        $this->assertSame(['guards', 'webhooks'], $result);
    }

    public function test_parse_trims_whitespace_from_string_values(): void
    {
        $result = CloudDisableParser::parse('  guards ,  webhooks  ');
        $this->assertSame(['guards', 'webhooks'], $result);
    }

    public function test_parse_filters_empty_tokens_from_string(): void
    {
        $result = CloudDisableParser::parse('guards,,webhooks,');
        $this->assertSame(['guards', 'webhooks'], $result);
    }

    public function test_parse_normalises_array_input(): void
    {
        $result = CloudDisableParser::parse(['guards', ' webhooks ', '']);
        $this->assertSame(['guards', 'webhooks'], $result);
    }

    public function test_parse_strips_non_scalar_array_values(): void
    {
        $result = CloudDisableParser::parse(['guards', [], 'webhooks']);
        $this->assertSame(['guards', 'webhooks'], $result);
    }

    public function test_parse_handles_null_as_empty_string(): void
    {
        $this->assertSame([], CloudDisableParser::parse(null));
    }

    public function test_parse_single_value_string(): void
    {
        $result = CloudDisableParser::parse('guards');
        $this->assertSame(['guards'], $result);
    }

    public function test_parse_re_indexes_output(): void
    {
        $result = CloudDisableParser::parse(['guards', '', 'webhooks']);
        $this->assertSame([0 => 'guards', 1 => 'webhooks'], $result);
    }
}
