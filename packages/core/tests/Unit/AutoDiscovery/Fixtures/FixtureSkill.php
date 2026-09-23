<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\AutoDiscovery\Fixtures;

use PhpClaw\AutoDiscovery\Attributes\Skill;

#[Skill(name: 'fixture_skill', keywords: ['fix', 'test'], since: '1.1.0')]
final class FixtureSkill {}
