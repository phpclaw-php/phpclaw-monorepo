<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Memory;

use PhpClaw\Magento\Memory\MessageRole;
use PHPUnit\Framework\TestCase;

final class MessageRoleTest extends TestCase
{
    public function test_values_returns_all_four_role_strings(): void
    {
        $values = MessageRole::values();

        self::assertSame(['user', 'assistant', 'tool', 'tool_batch'], $values);
    }

    public function test_try_from_returns_case_for_known_value(): void
    {
        self::assertSame(MessageRole::User, MessageRole::from('user'));
        self::assertSame(MessageRole::Assistant, MessageRole::from('assistant'));
        self::assertSame(MessageRole::Tool, MessageRole::from('tool'));
        self::assertSame(MessageRole::ToolBatch, MessageRole::from('tool_batch'));
    }

    public function test_try_from_returns_null_for_unknown_value(): void
    {
        self::assertNull(MessageRole::tryFrom('unknown'));
        self::assertNull(MessageRole::tryFrom(''));
    }
}
