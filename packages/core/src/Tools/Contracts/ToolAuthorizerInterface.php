<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Contracts;

/**
 * Adapter-supplied answer to the only two questions a core tool asks about its caller: whether
 * this run came from the platform's console, and whether an authenticated caller is present.
 */
interface ToolAuthorizerInterface
{
    public const CAPABILITY = 'phpclaw_use_tools';

    /**
     * Report whether an authenticated caller is present.
     *
     * @return bool
     */
    public function allows(): bool;

    /**
     * Report whether this run came from the platform's console.
     *
     * @return bool
     */
    public function runningInConsole(): bool;
}
