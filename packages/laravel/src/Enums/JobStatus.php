<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Enums;

/**
 * Status vocabulary for queued phpClaw agent jobs.
 */
enum JobStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
}
