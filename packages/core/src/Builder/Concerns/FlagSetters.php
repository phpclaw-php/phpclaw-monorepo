<?php

declare(strict_types=1);

namespace PhpClaw\Builder\Concerns;

use PhpClaw\Memory\Contracts\MemoryInterface;

/**
 * Behavioural toggles and memory-driver setters for ClawBuilder.
 */
trait FlagSetters
{
    private bool $storeMessages = true;

    private bool $useDefaultGuards = true;

    private bool $sanitiseOutput = true;

    private bool $compactHistory = true;

    private ?MemoryInterface $memory = null;

    /**
     * Whether to persist message content. Default true.
     *
     * @param  bool  $flag  True to persist prompt + response content; false to drop content from memory and cloud payloads.
     * @return static Builder instance for fluent chaining.
     */
    public function storeMessages(bool $flag = true): static
    {
        $this->storeMessages = $flag;

        return $this;
    }

    /**
     * Register the default guard chain. Default true.
     *
     * @param  bool  $flag  True to auto-register the seven built-in default guards (Injection, CodeInjection, RoleSwitch, Homoglyph, Unicode, MessageLength, PiiDetection) on build.
     * @return static Builder instance for fluent chaining.
     */
    public function useDefaultGuards(bool $flag = true): static
    {
        $this->useDefaultGuards = $flag;

        return $this;
    }

    /**
     * Whether to sanitise LLM output: strips PHP tags and redacts dangerous function calls. Default true.
     *
     * @param  bool  $enabled  False to return raw LLM output unchanged (useful for documentation or explanation use cases).
     * @return static Builder instance for fluent chaining.
     */
    public function sanitiseOutput(bool $enabled = true): static
    {
        $this->sanitiseOutput = $enabled;

        return $this;
    }

    /**
     * Toggle automatic history compaction: the summary LLM call fired when history overflows.
     *
     * @param  bool  $enabled  False to disable compaction entirely (no hidden summary call).
     * @return static Builder instance for fluent chaining.
     */
    public function compactHistory(bool $enabled = true): static
    {
        $this->compactHistory = $enabled;

        return $this;
    }

    /**
     * Inject a memory driver for conversation persistence.
     *
     * @param  MemoryInterface  $memory  Memory driver used for conversation history + per-turn key/value state.
     * @return static Builder instance for fluent chaining.
     */
    public function memory(MemoryInterface $memory): static
    {
        $this->memory = $memory;

        return $this;
    }
}
