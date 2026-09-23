<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when a tool execution fails; non-final, extended by ShellDeniedException for shell-specific command-denial signals.
 */
class ToolException extends PhpClawException {}
