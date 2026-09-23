<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery\Fixtures;

use PhpClaw\AutoDiscovery\Attributes\Tool;

#[Tool(name: 'fixture_no_arg_tool', default: true)]
final class FixtureNoArgTool
{
    public function execute(): string
    {
        return 'no-arg';
    }
}
