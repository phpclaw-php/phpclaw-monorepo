<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery\Fixtures;

use PhpClaw\AutoDiscovery\Attributes\Provider;

#[Provider(name: 'fixture_provider', defaultModel: 'fixture-model-v1', since: '1.1.0')]
final class FixtureProvider {}
