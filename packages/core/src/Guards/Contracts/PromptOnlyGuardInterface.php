<?php

declare(strict_types=1);

namespace PhpClaw\Guards\Contracts;

/**
 * Marks a guard whose patterns are meaningful in a prompt but not in tool arguments, so it is
 * skipped when scanning a tool call's input.
 */
interface PromptOnlyGuardInterface extends GuardInterface {}
