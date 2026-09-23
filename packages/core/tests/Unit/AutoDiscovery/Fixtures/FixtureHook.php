<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery\Fixtures;

use PhpClaw\AutoDiscovery\Attributes\Hook;

#[Hook(event: 'agent.before', priority: 10, since: '1.1.0')]
#[Hook(event: 'agent.after', priority: 200)]
final class FixtureHook {}
