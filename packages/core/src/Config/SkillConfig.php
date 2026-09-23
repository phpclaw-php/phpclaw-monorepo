<?php

declare(strict_types=1);

namespace PhpClaw\Config;

use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Skill registration and matching settings for ClawConfig.
 */
final class SkillConfig
{
    public readonly array $skills;

    public readonly int $skillMatchLimit;

    public readonly array $remoteSkillUrls;

    /**
     * Group and validate the skill-facing configuration.
     *
     * @param  SkillInterface[]  $skills  Skills registered for keyword-matched context injection.
     * @param  int  $skillMatchLimit  Maximum matched skills injected per turn; clamped to at least 1.
     * @param  string[]  $remoteSkillUrls  Remote skill collection URLs loaded on build.
     * @return void
     */
    public function __construct(
        array $skills = [],
        int $skillMatchLimit = 3,
        array $remoteSkillUrls = [],
    ) {
        $this->skills = $skills;
        $this->skillMatchLimit = max(1, $skillMatchLimit);
        $this->remoteSkillUrls = $remoteSkillUrls;
    }
}
