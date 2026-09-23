<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Stubs;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Tools\Contracts\ToolInterface;

final class FailingTool implements ToolInterface
{
    public function name(): string
    {
        return 'fail';
    }

    public function description(): string
    {
        return 'Always throws a ToolException';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $input): string
    {
        throw new ToolException('simulated failure');
    }
}
