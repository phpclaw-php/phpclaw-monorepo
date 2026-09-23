<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery\Fixtures;

use PhpClaw\AutoDiscovery\Attributes\Tool;

#[Tool(
    name: 'fixture_default_tool',
    description: 'Default-on fixture',
    since: '1.1.0',
    default: true,
    needsConfig: ['workspaceRoot' => 'string'],
)]
final class FixtureDefaultTool
{
    public function __construct(public readonly ?string $workspaceRoot = null) {}
}
