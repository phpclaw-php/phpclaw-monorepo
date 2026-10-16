<?php

declare(strict_types=1);

namespace PhpClaw\Agent;

use PhpClaw\Tools\Contracts\ToolInterface;

/** A named, reusable agent: its instructions, tools, provider and model. */
abstract class AgentDefinition
{
    /**
     * What the agent does and how it behaves, sent as its system prompt.
     *
     * @return string
     */
    abstract public function instructions(): string;

    /**
     * The tools this agent may call; empty keeps the tools already on the builder.
     *
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * Provider slug for this agent; empty keeps the configured provider.
     *
     * @return string
     */
    public function provider(): string
    {
        return '';
    }

    /**
     * Model id for this agent; empty keeps the configured model.
     *
     * @return string
     */
    public function model(): string
    {
        return '';
    }
}
