<?php

declare(strict_types=1);

namespace PhpClaw\Providers\Contracts;

use PhpClaw\Providers\Tools\WebSearch;

/**
 * Capability contract for providers that run their own server-side web search.
 */
interface SupportsWebSearchInterface
{
    /**
     * Return a clone of the provider carrying the given provider-native tool configs.
     *
     * @param  WebSearch[]  $tools  Provider tool configs to attach.
     * @return static Cloned provider; the original instance is unchanged.
     */
    public function withProviderTools(array $tools): static;

    /**
     * Translate a WebSearch config into this provider's native payload fragment.
     *
     * @param  WebSearch  $tool  Provider-agnostic web search config.
     * @return array<string, mixed> Provider-native options; shape is provider-specific.
     */
    public function webSearchToolOptions(WebSearch $tool): array;
}
