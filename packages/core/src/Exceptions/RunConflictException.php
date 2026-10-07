<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when a run is saved from a stale copy: another process saved a newer version first.
 */
final class RunConflictException extends PhpClawException {}
