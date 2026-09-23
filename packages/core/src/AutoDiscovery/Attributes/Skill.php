<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery\Attributes;

use Attribute;

/**
 * Marks a class as a phpClaw skill, discoverable by AttributeScanner.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Skill
{
    /**
     * Create a new Skill instance.
     *
     * @param  string  $name  Skill identifier (kebab-case or snake_case, e.g. 'php_best_practices').
     * @param  string  $label  Human-readable display name. Falls back to a title-cased `name` when empty.
     * @param  list<string>  $keywords  Trigger keywords used by `SkillRegistry::match()` keyword scoring.
     * @param  string  $since  Package version this skill first shipped in.
     * @param  bool  $deprecated  Marked deprecated: discovered but flagged for adapter warnings.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label = '',
        public readonly array $keywords = [],
        public readonly string $since = '',
        public readonly bool $deprecated = false,
    ) {}
}
