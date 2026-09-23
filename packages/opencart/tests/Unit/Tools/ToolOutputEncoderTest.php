<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\OpenCart\Tools\ToolOutputEncoder;
use PHPUnit\Framework\TestCase;

final class ToolOutputEncoderTest extends TestCase
{
    public function test_returns_full_json_when_within_limit(): void
    {
        $data = ['items' => [['id' => 1], ['id' => 2]], 'total' => 2];
        $result = ToolOutputEncoder::truncate($data, 'items', 100000, 'test_tool');

        self::assertJson($result);
        $decoded = json_decode($result, true);
        self::assertCount(2, $decoded['items']);
    }

    public function test_truncates_rows_when_over_limit(): void
    {
        $rows = array_map(fn ($i) => ['id' => $i, 'name' => str_repeat('x', 100)], range(1, 20));
        $data = ['items' => $rows, 'total' => 20];

        $result = ToolOutputEncoder::truncate($data, 'items', 500, 'test_tool');

        self::assertStringContainsString('output truncated', $result);
        $jsonPart = explode("\n[...", $result)[0];
        $decoded = json_decode($jsonPart, true);
        self::assertNotNull($decoded, 'JSON before truncation notice must be valid');
        self::assertLessThan(20, count($decoded['items']));
    }

    public function test_truncation_notice_includes_total_and_shown_counts(): void
    {
        $rows = array_map(fn ($i) => ['id' => $i, 'data' => str_repeat('a', 200)], range(1, 10));
        $data = ['items' => $rows];

        $result = ToolOutputEncoder::truncate($data, 'items', 300, 'my_tool');

        self::assertMatchesRegularExpression('/\d+ total rows, \d+ shown/', $result);
    }

    public function test_returns_full_json_for_empty_list(): void
    {
        $data = ['items' => [], 'total' => 0];
        $result = ToolOutputEncoder::truncate($data, 'items', 100, 'test_tool');

        self::assertJson($result);
        $decoded = json_decode($result, true);
        self::assertCount(0, $decoded['items']);
    }

    public function test_uses_missing_list_key_gracefully(): void
    {
        $longValue = str_repeat('x', 200);
        $data = ['other_key' => $longValue];
        $result = ToolOutputEncoder::truncate($data, 'items', 50, 'test_tool');

        self::assertStringContainsString('output truncated', $result);
    }

    public function test_handles_empty_string_value_in_list(): void
    {
        $data = ['items' => [['id' => 1, 'name' => '']], 'total' => 1];
        $result = ToolOutputEncoder::truncate($data, 'items', 100000, 'test_tool');

        self::assertJson($result);
        $decoded = json_decode($result, true);
        self::assertSame('', $decoded['items'][0]['name']);
    }

    public function test_handles_multibyte_utf8_in_list_items(): void
    {
        $data = ['items' => [['name' => '日本語テスト']], 'total' => 1];
        $result = ToolOutputEncoder::truncate($data, 'items', 100000, 'test_tool');

        self::assertJson($result);
        $decoded = json_decode($result, true);
        self::assertSame('日本語テスト', $decoded['items'][0]['name']);
    }

    public function test_returns_unchanged_when_exactly_at_limit(): void
    {
        $data = ['items' => [['id' => 1]], 'total' => 1];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $limit = strlen($json);

        $result = ToolOutputEncoder::truncate($data, 'items', $limit, 'test_tool');

        self::assertJson($result);
        self::assertStringNotContainsString('output truncated', $result);
    }
}
