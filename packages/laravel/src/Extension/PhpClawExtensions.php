<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Extension;

/**
 * Mutable bucket passed to `phpclaw.booting` listeners for registering subsystem extensions.
 */
final class PhpClawExtensions
{
    public array $tools = [];

    public array $guards = [];

    public array $hooks = [];

    public array $skills = [];

    public array $memory = [];

    public array $providers = [];
}
