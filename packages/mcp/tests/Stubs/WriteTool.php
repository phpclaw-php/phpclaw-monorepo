<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Stubs;

use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

final class WriteTool implements MutatingToolInterface, ToolInterface
{
    public function name(): string
    {
        return 'write';
    }

    public function description(): string
    {
        return 'Writes something';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): string
    {
        return 'written';
    }
}
