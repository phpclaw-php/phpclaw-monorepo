<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery\Fixtures;

use PhpClaw\AutoDiscovery\Attributes\Hook;

#[Hook(event: Hook::ANY_EVENT, priority: 50)]
final class FixtureWildcardHook {}
