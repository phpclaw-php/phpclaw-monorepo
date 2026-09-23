<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery\Fixtures;

use PhpClaw\AutoDiscovery\Attributes\Guard;

#[Guard(priority: 25, since: '1.1.0')]
final class FixtureGuard {}
