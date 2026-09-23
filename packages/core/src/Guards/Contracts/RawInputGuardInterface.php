<?php

declare(strict_types=1);

namespace PhpClaw\Guards\Contracts;

/**
 * Marks a guard that scans the raw user input rather than the augmented message.
 */
interface RawInputGuardInterface extends GuardInterface {}
