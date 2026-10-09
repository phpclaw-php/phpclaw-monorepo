<?php

declare(strict_types=1);

namespace PhpClaw\Skills;

use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Inline skill defined directly in PHP: no file I/O required.
 */
final class ArraySkill implements SkillInterface
{
    /**
     * Create a new ArraySkill instance.
     *
     * @param  string  $name  Unique machine-readable identifier.
     * @param  string  $description  Short description shown to the model in the skills list.
     * @param  string[]  $tags  Tags for listings; not used to pick a skill.
     * @param  string  $content  Instruction text injected when this skill matches.
     * @return void
     */
    public function __construct(
        private string $name,
        private string $description,
        private array $tags,
        private string $content,
    ) {}

    /**
     * Unique machine-readable identifier.
     *
     * @return string
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Short human-readable description shown to the model in the skills list.
     *
     * @return string
     */
    public function description(): string
    {
        return $this->description;
    }

    /**
     * Tags for listings; not used to pick a skill.
     *
     * @return string[]
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * Instruction text injected into the user message when this skill matches.
     *
     * @return string
     */
    public function content(): string
    {
        return $this->content;
    }
}
