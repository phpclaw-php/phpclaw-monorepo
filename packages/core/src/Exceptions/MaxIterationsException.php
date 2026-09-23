<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when the ReAct agent loop hits the maxIterations cap without returning a final text response.
 */
final class MaxIterationsException extends PhpClawException {}
