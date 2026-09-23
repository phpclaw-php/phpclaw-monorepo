<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Service;

use PhpClaw\Drupal\Service\ToolCall;
use PHPUnit\Framework\TestCase;

final class ToolCallTest extends TestCase
{
    public function test_from_context_maps_keys(): void
    {
        $call = ToolCall::fromContext([
            'tool_name' => 'read_log',
            'tool_input' => ['entries' => 5],
            'tool_result' => '[]',
        ]);

        self::assertSame('read_log', $call->toolName);
        self::assertSame(['entries' => 5], $call->toolInput);
        self::assertSame('[]', $call->toolResult);
    }

    public function test_from_context_applies_defaults_and_is_not_named(): void
    {
        $call = ToolCall::fromContext([]);

        self::assertSame('', $call->toolName);
        self::assertSame([], $call->toolInput);
        self::assertSame('', $call->toolResult);
        self::assertFalse($call->isNamed());
    }

    public function test_is_named_true_when_name_present(): void
    {
        self::assertTrue(ToolCall::fromContext(['tool_name' => 'db_query'])->isNamed());
    }

    public function test_to_array_shape(): void
    {
        $call = new ToolCall('db_query', ['query' => 'SELECT 1'], '[{"id":1}]');

        self::assertSame(
            ['tool_name' => 'db_query', 'tool_input' => ['query' => 'SELECT 1'], 'tool_result' => '[{"id":1}]'],
            $call->toArray(),
        );
    }

    public function test_to_history_entry_shape(): void
    {
        $call = new ToolCall('db_query', ['query' => 'SELECT 1'], 'result');

        self::assertSame(
            ['role' => 'tool', 'content' => 'result', 'tool_name' => 'db_query', 'tool_input' => ['query' => 'SELECT 1']],
            $call->toHistoryEntry(),
        );
    }
}
