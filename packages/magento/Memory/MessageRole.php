<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Memory;

/**
 * Typed representation of valid message role values in phpclaw_messages.
 */
enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
    case ToolBatch = 'tool_batch';

    /**
     * Return all valid role string values.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
