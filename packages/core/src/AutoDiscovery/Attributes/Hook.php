<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery\Attributes;

use Attribute;

/**
 * Marks a class as a phpClaw lifecycle hook listener, discoverable by AttributeScanner.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class Hook
{
    public const ANY_EVENT = '*';

    public const DEFAULT_PRIORITY = 100;

    /**
     * Create a new Hook instance.
     *
     * @param  string  $event  Lifecycle event name (e.g. 'agent.before', 'tool.after'), or {@see Hook::ANY_EVENT}.
     * @param  int  $priority  Lower runs earlier. Negative priorities reserved for core defaults.
     * @param  string  $name  Hook identifier surfaced in admin UIs. Defaults to a slug derived from the class name + event when empty.
     * @param  string  $label  Human-readable display name. Falls back to the class short name when empty.
     * @param  bool  $enabledByDefault  When true, adapters auto-enable this hook in fresh installs.
     * @param  string  $since  Package version this hook first shipped in.
     * @param  bool  $deprecated  Marked deprecated: discovered but flagged for adapter warnings.
     */
    public function __construct(
        public readonly string $event,
        public readonly int $priority = self::DEFAULT_PRIORITY,
        public readonly string $name = '',
        public readonly string $label = '',
        public readonly bool $enabledByDefault = false,
        public readonly string $since = '',
        public readonly bool $deprecated = false,
    ) {}
}
