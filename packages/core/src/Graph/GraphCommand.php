<?php

declare(strict_types=1);

namespace PhpClaw\Graph;

/**
 * Node result that writes state keys and, when $goto is set, picks the next node instead of the edges.
 */
final class GraphCommand
{
    /**
     * Describe what the node wants to happen next.
     *
     * @param  string|null  $goto  Next node name or Graph::END; null follows the node's edges.
     * @param  array<string, mixed>  $update  State values to write, keyed by name.
     */
    public function __construct(
        public readonly ?string $goto = null,
        public readonly array $update = [],
    ) {}
}
