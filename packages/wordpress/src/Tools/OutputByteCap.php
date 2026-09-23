<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tools;

/**
 * Shared output-size cap for phpClaw tools.
 */
interface OutputByteCap
{
    public const MAX_OUTPUT_BYTES = 8192;
}
