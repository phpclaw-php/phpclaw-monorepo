<?php

declare(strict_types=1);

namespace PhpClaw\Hooks\Contracts;

/**
 * Optional interface for class-based hook handlers.
 */
interface HookInterface
{
    /**
     * Handle the fired event.
     *
     * @param  array<string, mixed>  $context  Event context data (read-only, mutations are ignored).
     * @return void
     */
    public function handle(array $context): void;
}
