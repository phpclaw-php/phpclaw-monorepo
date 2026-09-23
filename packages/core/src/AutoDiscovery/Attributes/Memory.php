<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery\Attributes;

use Attribute;

/**
 * Marks a class as a phpClaw memory driver, discoverable by AttributeScanner.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Memory
{
    /**
     * Create a new Memory instance.
     *
     * @param  string  $driver  Driver key matched against the adapter's `memory_driver` setting.
     * @param  string  $label  Human-readable display name. Falls back to title-cased `driver` when empty.
     * @param  string  $since  Package version this driver first shipped in.
     * @param  bool  $deprecated  Marked deprecated: discovered but flagged for adapter warnings.
     */
    public function __construct(
        public readonly string $driver,
        public readonly string $label = '',
        public readonly string $since = '',
        public readonly bool $deprecated = false,
    ) {}
}
