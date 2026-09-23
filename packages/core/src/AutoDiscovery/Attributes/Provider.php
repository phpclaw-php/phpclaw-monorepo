<?php

declare(strict_types=1);

namespace PhpClaw\AutoDiscovery\Attributes;

use Attribute;

/**
 * Marks a class as a phpClaw LLM provider, discoverable by AttributeScanner.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Provider
{
    /**
     * Create a new Provider instance.
     *
     * @param  string  $name  Provider key (e.g. 'anthropic', 'openai'). Lowercase, no whitespace.
     * @param  string  $defaultModel  Model identifier used when no explicit model is set on the builder.
     * @param  string  $label  Human-readable display name shown in admin dropdowns. Falls back to the title-cased `name` when empty.
     * @param  string  $since  Package version this provider first shipped in.
     * @param  bool  $deprecated  Marked deprecated: discovered but flagged for adapter warnings.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $defaultModel = '',
        public readonly string $label = '',
        public readonly string $since = '',
        public readonly bool $deprecated = false,
    ) {}
}
