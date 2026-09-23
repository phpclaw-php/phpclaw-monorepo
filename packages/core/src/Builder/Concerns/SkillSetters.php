<?php

declare(strict_types=1);

namespace PhpClaw\Builder\Concerns;

use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Skill registration and matching setters for ClawBuilder.
 */
trait SkillSetters
{
    private array $skills = [];

    private int $skillMatchLimit = 3;

    private array $remoteSkillUrls = [];

    /**
     * Replace the skills list.
     *
     * @param  SkillInterface[]  $skills  Full skill list to register on build; replaces any previously set skills.
     * @return static Builder instance for fluent chaining.
     */
    public function skills(array $skills): static
    {
        $this->skills = $skills;

        return $this;
    }

    /**
     * Append a single skill to the list.
     *
     * @param  SkillInterface  $skill  Skill to register; appended to the existing skills array.
     * @return static Builder instance for fluent chaining.
     */
    public function addSkill(SkillInterface $skill): static
    {
        $this->skills[] = $skill;

        return $this;
    }

    /**
     * Number of skills injected per turn. Default 3; clamped to at least 1.
     *
     * @param  int  $limit  Maximum matched skills injected into each request.
     * @return static Builder instance for fluent chaining.
     */
    public function skillMatchLimit(int $limit): static
    {
        $this->skillMatchLimit = max(1, $limit);

        return $this;
    }

    /**
     * Register a remote skill collection URL loaded on build.
     *
     * @param  string  $url  HTTPS URL of a phpClaw JSON collection or a single SKILL.md.
     * @return static Builder instance for fluent chaining.
     */
    public function withRemoteSkills(string $url): static
    {
        $this->remoteSkillUrls[] = $url;

        return $this;
    }
}
