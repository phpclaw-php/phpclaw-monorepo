<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Stubs;

use PhpClaw\Tools\Contracts\PerInvocationMutabilityInterface;
use PhpClaw\Tools\Contracts\ToolInterface;

final class SometimesWriteTool implements PerInvocationMutabilityInterface, ToolInterface
{
    public function name(): string
    {
        return 'sometimes_write';
    }

    public function description(): string
    {
        return 'Reads or writes depending on the action';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): string
    {
        return 'ok';
    }

    public function isMutating(array $input): bool
    {
        return ($input['action'] ?? '') === 'write';
    }
}
