<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Skills;

use PhpClaw\Exceptions\SkillException;
use PhpClaw\Skills\FileSkill;
use PHPUnit\Framework\TestCase;

final class FileSkillTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    private function writeTempSkill(string $content): string
    {
        $path = sys_get_temp_dir().'/phpclaw_skill_test_'.uniqid().'.md';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_name_parsed_from_frontmatter(): void
    {
        $path = $this->writeTempSkill("---\nname: deploy-skill\ndescription: desc\ntags: []\n---\nContent here.");
        $skill = new FileSkill($path);

        $this->assertSame('deploy-skill', $skill->name());
    }

    public function test_description_parsed_from_frontmatter(): void
    {
        $path = $this->writeTempSkill("---\nname: s\ndescription: My description\ntags: []\n---\nContent.");
        $skill = new FileSkill($path);

        $this->assertSame('My description', $skill->description());
    }

    public function test_tags_parsed_from_inline_array(): void
    {
        $path = $this->writeTempSkill("---\nname: s\ndescription: d\ntags: [docker, php, deploy]\n---\nContent.");
        $skill = new FileSkill($path);

        $this->assertSame(['docker', 'php', 'deploy'], $skill->tags());
    }

    public function test_content_is_markdown_body_after_frontmatter(): void
    {
        $path = $this->writeTempSkill("---\nname: s\ndescription: d\ntags: []\n---\n\nAlways write tests.\nBe careful.");
        $skill = new FileSkill($path);

        $this->assertStringContainsString('Always write tests.', $skill->content());
        $this->assertStringContainsString('Be careful.', $skill->content());
    }

    public function test_content_is_trimmed(): void
    {
        $path = $this->writeTempSkill("---\nname: s\ndescription: d\ntags: []\n---\n\n  trimmed  \n");
        $skill = new FileSkill($path);

        $this->assertSame('trimmed', $skill->content());
    }

    public function test_name_defaults_to_filename_without_extension(): void
    {
        $path = $this->writeTempSkill("---\ndescription: d\ntags: []\n---\nContent.");
        $skill = new FileSkill($path);

        $this->assertSame(basename($path, '.md'), $skill->name());
    }

    public function test_tags_defaults_to_empty_array_when_missing(): void
    {
        $path = $this->writeTempSkill("---\nname: s\ndescription: d\n---\nContent.");
        $skill = new FileSkill($path);

        $this->assertSame([], $skill->tags());
    }

    public function test_throws_when_file_not_found(): void
    {
        $this->expectException(SkillException::class);
        new FileSkill('/nonexistent/path/skill.md');
    }

    public function test_throws_when_frontmatter_missing(): void
    {
        $path = $this->writeTempSkill("No frontmatter here.\nJust plain content.");

        $this->expectException(SkillException::class);
        new FileSkill($path);
    }

    public function test_throws_when_frontmatter_unclosed(): void
    {
        $path = $this->writeTempSkill("---\nname: s\ndescription: d\nContent without closing dashes.");

        $this->expectException(SkillException::class);
        new FileSkill($path);
    }
}
