<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit\Defaults\Stubs;

use PhpClaw\Tools\Contracts\ToolInterface;

final class DummyTool implements ToolInterface
{
    public function name(): string
    {
        return 'dummy';
    }

    public function description(): string
    {
        return 'A dummy tool for testing';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function execute(array $input): string
    {
        return 'dummy result';
    }
}
