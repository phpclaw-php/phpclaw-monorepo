<?php

declare(strict_types=1);

namespace PhpClaw\Builder\Concerns;

/**
 * phpClaw Cloud key and feature setters for ClawBuilder.
 */
trait CloudSetters
{
    private string $cloudKey = '';

    private string $cloudSigningSecret = '';

    private array $cloudDisable = [];

    /**
     * phpClaw Cloud API key. Empty = local-only mode.
     *
     * @param  string  $key  Cloud key; '' keeps the engine in local-only mode and skips CloudManager::boot().
     * @return static Builder instance for fluent chaining.
     */
    public function cloudKey(string $key): static
    {
        $this->cloudKey = $key;

        return $this;
    }

    /**
     * Cloud features to exclude from activation.
     *
     * @param  string[]  $features  Cloud feature slugs to skip when CloudManager boots (e.g. 'scan').
     * @return static Builder instance for fluent chaining.
     */
    public function cloudDisable(array $features): static
    {
        $this->cloudDisable = $features;

        return $this;
    }

    /**
     * Shared secret used to verify signed cloud scan responses. Empty leaves verification off.
     *
     * @param  string  $secret  Signing secret matching the cloud receiver; '' disables verification.
     * @return static Builder instance for fluent chaining.
     */
    public function cloudSigningSecret(string $secret): static
    {
        $this->cloudSigningSecret = $secret;

        return $this;
    }
}
