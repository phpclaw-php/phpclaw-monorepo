<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery\Attributes;

use Attribute;

/**
 * Marks a class as a phpClaw guard, discoverable by AttributeScanner.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Guard
{
    /**
     * Create a new Guard instance.
     *
     * @param  int  $priority  Lower runs earlier. Core defaults use 0-5; extras typically 10+.
     * @param  string  $name  Guard identifier surfaced in admin UIs. Defaults to a slug derived from the class name when empty.
     * @param  string  $label  Human-readable display name. Falls back to the class short name when empty.
     * @param  bool  $enabledByDefault  When true, adapters auto-enable this guard in fresh installs.
     * @param  string  $since  Package version this guard first shipped in.
     * @param  bool  $deprecated  Marked deprecated: discovered but flagged for adapter warnings.
     */
    public function __construct(
        public readonly int $priority = 100,
        public readonly string $name = '',
        public readonly string $label = '',
        public readonly bool $enabledByDefault = false,
        public readonly string $since = '',
        public readonly bool $deprecated = false,
    ) {}
}
