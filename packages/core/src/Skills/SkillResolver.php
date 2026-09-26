<?php

declare(strict_types=1);

namespace PhpClaw\Skills;

use PhpClaw\Exceptions\SkillException;
use PhpClaw\Skills\Contracts\SkillInterface;

/**
 * Resolve a config-entries array into Skill instances.
 */
final class SkillResolver
{
    /**
     * Resolve a list of config entries into Skill instances.
     *
     * @param  list<mixed>  $entries  Normalised array of config entries (each entry should be an array).
     * @param  callable|null  $onSkip  Callback invoked when an entry is skipped.
     * @return list<SkillInterface>
     */
    public static function resolve(array $entries, ?callable $onSkip = null): array
    {
        $skills = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                self::reportSkip($onSkip, $entry, 'not-an-array');

                continue;
            }

            $skill = self::instantiate($entry, $onSkip);

            if ($skill !== null) {
                $skills[] = $skill;
            }
        }

        return $skills;
    }

    /**
     * Build a single Skill instance from a parsed entry, or return null when the entry is malformed or the class is not loadable.
     *
     * @param  array<string, mixed>  $entry  Parsed skill entry (file, class, or inline shape).
     * @param  callable|null  $onSkip  Callback invoked when an entry is skipped.
     * @return SkillInterface|null The instantiated skill, or null when the entry cannot be resolved.
     */
    private static function instantiate(array $entry, ?callable $onSkip): ?SkillInterface
    {
        if (isset($entry['file']) && is_string($entry['file'])) {
            try {
                return new FileSkill($entry['file']);
            } catch (SkillException $e) {
                self::reportSkip($onSkip, $entry, 'file-error: '.$e->getMessage());

                return null;
            }
        }

        if (isset($entry['class']) && is_string($entry['class'])) {
            $class = $entry['class'];

            if (! class_exists($class)) {
                self::reportSkip($onSkip, $entry, 'class-not-found: '.$class);

                return null;
            }

            if (! is_a($class, SkillInterface::class, true)) {
                self::reportSkip($onSkip, $entry, 'class-not-a-skill: '.$class);

                return null;
            }

            return new $class;
        }

        if (isset($entry['name'], $entry['description'], $entry['content'])) {
            return new ArraySkill(
                name: (string) $entry['name'],
                description: (string) $entry['description'],
                tags: array_values(array_filter((array) ($entry['tags'] ?? []), 'is_string')),
                content: (string) $entry['content'],
            );
        }

        self::reportSkip($onSkip, $entry, 'unrecognised-shape');

        return null;
    }

    /**
     * Invoke the skip callback when one is provided.
     *
     * @param  callable|null  $onSkip  Optional callback to invoke.
     * @param  mixed  $entry  The skipped entry passed to the callback.
     * @param  string  $reason  Machine-readable skip reason.
     * @return void
     */
    private static function reportSkip(?callable $onSkip, mixed $entry, string $reason): void
    {
        if ($onSkip !== null) {
            $onSkip($entry, $reason);
        }
    }
}
