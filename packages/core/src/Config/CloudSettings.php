<?php

declare(strict_types=1);

namespace PhpClaw\Config;

/**
 * phpClaw Cloud key and feature settings for ClawConfig.
 */
final class CloudSettings
{
    /**
     * Group the cloud-facing configuration.
     *
     * @param  string  $cloudKey  phpClaw Cloud API key; '' keeps the engine in local-only mode.
     * @param  string[]  $cloudDisable  Cloud feature slugs to skip when CloudManager boots.
     * @param  string  $cloudSigningSecret  Shared secret for verifying signed cloud scan responses; '' leaves verification off.
     * @return void
     */
    public function __construct(
        public readonly string $cloudKey = '',
        public readonly array $cloudDisable = [],
        public readonly string $cloudSigningSecret = '',
    ) {}
}
