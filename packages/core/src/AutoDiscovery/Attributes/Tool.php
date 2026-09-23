<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery\Attributes;

use Attribute;

/**
 * Marks a class as a phpClaw tool, discoverable by AttributeScanner.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Tool
{
    /**
     * Create a new Tool instance.
     *
     * @param  string  $name  Stable identifier the LLM uses to call the tool (snake_case).
     * @param  string  $description  Human-readable summary used in tool catalogues.
     * @param  string  $since  Package version this tool first shipped in.
     * @param  bool  $default  When true, adapters' `instantiateCoreTools()` auto-creates an instance.
     * @param  array<string, string>  $needsConfig  Map of constructor arg name => type-hint string the adapter must supply.
     * @param  bool  $deprecated  Marked deprecated: discovered but flagged for adapter warnings.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $since = '',
        public readonly bool $default = false,
        public readonly array $needsConfig = [],
        public readonly bool $deprecated = false,
    ) {}
}
