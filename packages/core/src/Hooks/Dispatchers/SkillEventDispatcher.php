<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Dispatchers;

use PhpClaw\Hooks\EventPayload;
use PhpClaw\Hooks\LifecycleEvent;

/**
 * Typed dispatchers for skill-activation lifecycle events.
 *
 * @internal
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
        self::dispatch(LifecycleEvent::SkillRegistered, ['key' => $key, 'class' => $class, 'label' => $label], $runId, $parentRunId);
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
        self::dispatch(LifecycleEvent::SkillLoaded, ['skill_name' => $skillName, 'skill_class' => $skillClass], $runId, $parentRunId);
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
        self::dispatch(LifecycleEvent::SkillMatched, [
            'matched_skills' => $matchedSkills,
            'message_excerpt' => $messageExcerpt,
            'matched_count' => count($matchedSkills),
        ], $runId, $parentRunId);
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
        self::dispatch(LifecycleEvent::SkillNotMatched, [
            'message_excerpt' => $messageExcerpt,
            'available_skills' => $availableSkills,
        ], $runId, $parentRunId);
    }

    /**
     * Fire a skill lifecycle event via EventPayload.
     *
     * @param  LifecycleEvent  $event  Event to fire.
     * @param  array<string, mixed>  $payload  Event context data.
     * @param  string  $runId  Active run ID, if any.
     * @param  string  $parentRunId  Parent run ID, if any.
     * @return void
     */
    private static function dispatch(LifecycleEvent $event, array $payload, string $runId, string $parentRunId): void
    {
        EventPayload::fire($event->value, $payload, runId: $runId, parentRunId: $parentRunId);
    }
}
