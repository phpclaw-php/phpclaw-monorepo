<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Skills;

use PhpClaw\Skills\ArraySkill;
use PHPUnit\Framework\TestCase;

final class ArraySkillTest extends TestCase
{
    private ArraySkill $skill;

    protected function setUp(): void
    {
        $this->skill = new ArraySkill(
            name: 'my-skill',
            description: 'A test skill',
            tags: ['php', 'testing'],
            content: 'Always write tests.',
        );
    }

    public function test_name_returns_configured_value(): void
    {
        $this->assertSame('my-skill', $this->skill->name());
    }

    public function test_description_returns_configured_value(): void
    {
        $this->assertSame('A test skill', $this->skill->description());
    }

    public function test_tags_returns_configured_array(): void
    {
        $this->assertSame(['php', 'testing'], $this->skill->tags());
    }

    public function test_content_returns_configured_value(): void
    {
        $this->assertSame('Always write tests.', $this->skill->content());
    }

    public function test_empty_tags_allowed(): void
    {
        $skill = new ArraySkill('s', 'd', [], 'c');
        $this->assertSame([], $skill->tags());
    }
}
