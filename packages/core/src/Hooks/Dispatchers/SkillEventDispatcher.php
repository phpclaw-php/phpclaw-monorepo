<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\EventPayload;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for skill-activation lifecycle events.
 */
final class SkillEventDispatcher
{
    /**
     * Fires when a skill class is registered in SkillCatalogue.
     *
     * @param  string  $key  Unique skill key.
     * @param  string  $class  FQCN of the skill implementation.
     * @param  string|null  $label  Optional human-readable label.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function registered(
        string $key,
        string $class,
        ?string $label = null,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::SkillRegistered->value,
            ['key' => $key, 'class' => $class, 'label' => $label],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }

    /**
     * Fires when a skill instance is loaded into SkillRegistry.
     *
     * @param  string  $skillName  Registered skill name.
     * @param  string  $skillClass  FQCN of the skill implementation.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function loaded(
        string $skillName,
        string $skillClass,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::SkillLoaded->value,
            ['skill_name' => $skillName, 'skill_class' => $skillClass],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }

    /**
     * Fires when one or more skills match the user message.
     *
     * @param  list<string>  $matchedSkills  Skills activated for this message.
     * @param  string  $messageExcerpt  First 200 chars of the user message.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function matched(
        array $matchedSkills,
        string $messageExcerpt,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::SkillMatched->value,
            [
                'matched_skills' => $matchedSkills,
                'message_excerpt' => $messageExcerpt,
                'matched_count' => count($matchedSkills),
            ],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }

    /**
     * Fires when no skill matches the user message.
     *
     * @param  string  $messageExcerpt  First 200 chars of the user message.
     * @param  list<string>  $availableSkills  All registered skill names.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    public static function notMatched(
        string $messageExcerpt,
        array $availableSkills,
        string $runId = '',
        string $parentRunId = '',
    ): void {
        EventPayload::fire(
            LifecycleEvent::SkillNotMatched->value,
            [
                'message_excerpt' => $messageExcerpt,
                'available_skills' => $availableSkills,
            ],
            runId: $runId,
            parentRunId: $parentRunId,
        );
    }
}
