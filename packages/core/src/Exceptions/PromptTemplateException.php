<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown when a PromptTemplate placeholder is missing a value or the value cannot be stringified.
 */
final class PromptTemplateException extends PhpClawException {}
