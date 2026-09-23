<?php

declare(strict_types=1);

namespace PhpClaw\Tools\Contracts;

/**
 * Marker for state-changing tools (write, delete, execute) that must pass an approval gate before running.
 */
interface MutatingToolInterface {}
