<?php

declare(strict_types=1);

namespace PhpClaw\Config;

use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Behavioural toggles and the memory driver for ClawConfig.
 */
final class RuntimeConfig
{
    /**
     * Group the behavioural configuration.
     *
     * @param  bool  $storeMessages  True to persist prompt + response content to memory and cloud payloads.
     * @param  bool  $useDefaultGuards  True to register the seven built-in default guards on build.
     * @param  bool  $sanitiseOutput  Whether to sanitise LLM output.
     * @param  bool  $compactHistory  Whether history compaction runs (false disables the summary LLM call).
     * @param  MemoryInterface|null  $memory  Memory driver for conversation persistence; null = stateless.
     * @return void
     */
    public function __construct(
        public readonly bool $storeMessages = true,
        public readonly bool $useDefaultGuards = true,
        public readonly bool $sanitiseOutput = true,
        public readonly bool $compactHistory = true,
        public readonly ?MemoryInterface $memory = null,
    ) {}
}
