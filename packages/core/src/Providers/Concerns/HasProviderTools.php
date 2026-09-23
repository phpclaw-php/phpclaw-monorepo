<?php

declare(strict_types=1);

namespace PhpClaw\Providers\Concerns;

use PhpClaw\Providers\Tools\WebSearch;

/**
 * Shared provider-native-tool attachment for SupportsWebSearchInterface implementers.
 */
trait HasProviderTools
{
    private array $providerTools = [];

    /**
     * Return a clone of the provider carrying the given provider-native tool configs.
     *
     * @param  WebSearch[]  $tools  Provider tool configs to attach.
     * @return static Cloned provider; the original instance is unchanged.
     */
    public function withProviderTools(array $tools): static
    {
        $clone = clone $this;
        $clone->providerTools = $tools;

        return $clone;
    }
}
