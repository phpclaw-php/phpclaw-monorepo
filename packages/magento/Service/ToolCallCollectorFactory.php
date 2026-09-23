<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Service;

/**
 * Factory for ToolCallCollector.
 */
final class ToolCallCollectorFactory
{
    /**
     * Create a new, empty ToolCallCollector for one agent turn.
     *
     * @return ToolCallCollector
     */
    public function create(): ToolCallCollector
    {
        return new ToolCallCollector;
    }
}
