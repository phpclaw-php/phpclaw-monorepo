<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when a stored run is missing or malformed, or is in a state that does not allow the requested action.
 */
final class RunStateException extends PhpClawException {}
