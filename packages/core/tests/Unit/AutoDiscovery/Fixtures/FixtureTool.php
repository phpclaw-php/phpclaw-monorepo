<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery\Fixtures;

use PhpClaw\AutoDiscovery\Attributes\Tool;

#[Tool(
    name: 'fixture_tool',
    description: 'Test fixture tool',
    since: '1.1.0',
    default: false,
)]
final class FixtureTool
{
    public function execute(): string
    {
        return 'fixture-tool-output';
    }
}
