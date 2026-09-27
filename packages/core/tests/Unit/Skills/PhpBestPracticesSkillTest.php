<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Skills;

use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\PhpBestPracticesSkill;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class PhpBestPracticesSkillTest extends TestCase
{
    private PhpBestPracticesSkill $skill;

    protected function setUp(): void
    {
        $this->skill = new PhpBestPracticesSkill;
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
    }

    public function test_implements_skill_interface(): void
    {
        $this->assertInstanceOf(SkillInterface::class, $this->skill);
    }

    public function test_name_returns_non_empty_slug(): void
    {
        $name = $this->skill->name();
        $this->assertNotEmpty($name);
        $this->assertSame('php_best_practices', $name);
    }

    public function test_description_is_non_empty_string(): void
    {
        $this->assertNotEmpty($this->skill->description());
    }

    public function test_tags_returns_array_of_strings(): void
    {
        $tags = $this->skill->tags();
        $this->assertIsArray($tags);
        $this->assertNotEmpty($tags);

        foreach ($tags as $tag) {
            $this->assertIsString($tag);
        }
    }

    public function test_tags_contains_key_php_and_code(): void
    {
        $this->assertContains('php', $this->skill->tags());
        $this->assertContains('code', $this->skill->tags());
        $this->assertContains('review', $this->skill->tags());
    }

    public function test_content_is_non_empty_string(): void
    {
        $this->assertNotEmpty($this->skill->content());
    }

    public function test_content_contains_strict_types_rule(): void
    {
        $this->assertStringContainsString('strict_types', $this->skill->content());
    }

    public function test_content_contains_final_class_rule(): void
    {
        $this->assertStringContainsString('final', $this->skill->content());
    }

    public function test_content_contains_return_type_rule(): void
    {
        $this->assertStringContainsString('return type', $this->skill->content());
    }
}
