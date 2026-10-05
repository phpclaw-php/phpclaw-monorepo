<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when a JSON Schema passed to sendStructured() uses a keyword JsonSchemaValidator does not enforce.
 */
final class UnsupportedSchemaException extends PhpClawException {}
