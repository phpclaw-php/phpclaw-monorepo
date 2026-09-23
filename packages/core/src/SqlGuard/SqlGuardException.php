<?php

declare(strict_types=1);

namespace PhpClaw\SqlGuard;

/**
 * Thrown when a query is rejected as not being a safe single read-only statement.
 */
final class SqlGuardException extends \RuntimeException {}
