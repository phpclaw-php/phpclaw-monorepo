<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Magento\Service\ToolCall;
use PHPUnit\Framework\TestCase;

final class ToolCallTest extends TestCase
{
    public function test_from_context_maps_keys(): void
    {
        $call = ToolCall::fromContext([
            'tool_name' => 'db_query',
            'tool_input' => ['query' => 'SELECT 1'],
            'tool_result' => '[{"1":1}]',
        ]);

        self::assertSame('db_query', $call->toolName);
        self::assertSame(['query' => 'SELECT 1'], $call->toolInput);
        self::assertSame('[{"1":1}]', $call->toolResult);
    }

    public function test_from_context_applies_defaults(): void
    {
        $call = ToolCall::fromContext([]);

        self::assertSame('', $call->toolName);
        self::assertSame([], $call->toolInput);
        self::assertSame('', $call->toolResult);
        self::assertFalse($call->isNamed());
    }

    public function test_is_named_true_when_name_present(): void
    {
        self::assertTrue(ToolCall::fromContext(['tool_name' => 'shell'])->isNamed());
    }

    public function test_to_array_shape(): void
    {
        $call = new ToolCall('http', ['url' => 'x'], '{"status":200}');

        self::assertSame(
            ['tool_name' => 'http', 'tool_input' => ['url' => 'x'], 'tool_result' => '{"status":200}'],
            $call->toArray(),
        );
    }

    public function test_to_history_entry_shape(): void
    {
        $call = new ToolCall('http', ['url' => 'x'], 'body');

        self::assertSame(
            ['role' => 'tool', 'content' => 'body', 'tool_name' => 'http', 'tool_input' => ['url' => 'x']],
            $call->toHistoryEntry(),
        );
    }
}
