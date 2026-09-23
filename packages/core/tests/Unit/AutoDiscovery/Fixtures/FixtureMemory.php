<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery\Fixtures;

use PhpClaw\AutoDiscovery\Attributes\Memory;

#[Memory(driver: 'fixture_driver', since: '1.1.0')]
final class FixtureMemory {}
