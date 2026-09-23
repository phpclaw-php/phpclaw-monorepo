<?php

declare(strict_types=1);

namespace PhpClaw\Exceptions;

/**
 * Thrown by an approval gate to deny a pending tool call.
 */
final class HumanDeniedException extends PhpClawException
{
    /**
     * Create a new HumanDeniedException instance.
     *
     * @param  string  $toolName  Denied tool.
     * @param  array<string, mixed>  $toolInput  Input that was denied, retained for audit.
     */
    public function __construct(
        public readonly string $toolName,
        public readonly array $toolInput = [],
    ) {
        parent::__construct("Action denied by human: {$toolName}");
    }
}
