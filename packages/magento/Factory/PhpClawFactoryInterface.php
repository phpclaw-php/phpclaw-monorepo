<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Factory;

use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Memory\Contracts\MemoryInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

/**
 * Contract for the phpClaw engine factory.
 */
interface PhpClawFactoryInterface
{
    /**
     * Build and return a fully configured phpClaw agent instance.
     *
     * @return PhpClawInterface
     */
    public function create(): PhpClawInterface;

    /**
     * Resolve the configured MemoryInterface for the current driver setting.
     *
     * @return MemoryInterface
     */
    public function resolveMemory(): MemoryInterface;

    /**
     * Expose the resolved tool list for the MCP server registry.
     *
     * @return array<int, ToolInterface>
     */
    public function buildTools(): array;

    /**
     * Expose every tool this adapter registers, before the provider profile filter narrows them.
     *
     * @return array<int, ToolInterface>
     */
    public function registeredTools(): array;
}
