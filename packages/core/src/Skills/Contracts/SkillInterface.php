<?php

declare(strict_types=1);

namespace PhpClaw\Skills\Contracts;

/**
 * A single skill: matched per-turn and injected into the user message.
 */
interface SkillInterface
{
    /**
     * Unique machine-readable identifier.
     *
     * @return string
     */
    public function name(): string;

    /**
     * Short human-readable description used in keyword matching.
     *
     * @return string
     */
    public function description(): string;

    /**
     * Tags used for keyword matching.
     *
     * @return string[]
     */
    public function tags(): array;

    /**
     * Instruction text injected into the user message when this skill matches.
     *
     * @return string
     */
    public function content(): string;
}
