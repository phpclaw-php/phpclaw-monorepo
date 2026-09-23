<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Skills;

use PhpClaw\Joomla\Component\Administrator\Engine\EngineBootstrapper;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class PhpClawPluginSkillsTest extends TestCase
{
    protected function setUp(): void
    {
        SkillRegistry::reset();
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
    }

    private function registerConfigSkills(string $skillsJson): void
    {
        $method = new \ReflectionMethod(EngineBootstrapper::class, 'registerConfigSkills');
        $method->setAccessible(true);
        $method->invoke(new EngineBootstrapper, new PhpClawConfig(skills: $skillsJson));
    }

    public function test_empty_skills_config_registers_nothing(): void
    {
        $this->registerConfigSkills('[]');

        self::assertSame([], SkillRegistry::all());
    }

    public function test_malformed_json_registers_nothing(): void
    {
        $this->registerConfigSkills('not json at all');

        self::assertSame([], SkillRegistry::all());
    }

    public function test_inline_array_skill_registered(): void
    {
        $this->registerConfigSkills((string) json_encode([[
            'name' => 'joomla-expert',
            'description' => 'Joomla best practices',
            'tags' => ['joomla', 'cms'],
            'content' => 'Use Joomla MVC conventions.',
        ]]));

        $all = SkillRegistry::all();

        self::assertCount(1, $all);
        self::assertSame('joomla-expert', $all[0]->name());
        self::assertSame('Joomla best practices', $all[0]->description());
        self::assertSame(['joomla', 'cms'], $all[0]->tags());
        self::assertSame('Use Joomla MVC conventions.', $all[0]->content());
    }

    public function test_inline_skill_without_tags_still_registers(): void
    {
        $this->registerConfigSkills((string) json_encode([[
            'name' => 'no-tags',
            'description' => 'A skill with no tags key',
            'content' => 'Body.',
        ]]));

        $all = SkillRegistry::all();

        self::assertCount(1, $all);
        self::assertSame('no-tags', $all[0]->name());
    }

    public function test_missing_file_skill_skipped_silently(): void
    {
        $this->registerConfigSkills((string) json_encode([['file' => '/nonexistent/joomla-skill.md']]));

        self::assertSame([], SkillRegistry::all());
    }

    public function test_file_skill_registered_from_disk(): void
    {
        $path = sys_get_temp_dir().'/phpclaw-joomla-skill-'.uniqid().'.md';
        file_put_contents(
            $path,
            "---\nname: joomla-security\ndescription: Joomla security tips\ntags: [security, joomla]\n---\nAlways escape output.",
        );

        $this->registerConfigSkills((string) json_encode([['file' => $path]]));

        $all = SkillRegistry::all();

        self::assertCount(1, $all);
        self::assertSame('joomla-security', $all[0]->name());

        unlink($path);
    }

    public function test_nonexistent_class_skill_skipped_silently(): void
    {
        $this->registerConfigSkills((string) json_encode([['class' => 'App\\Skills\\JoomlaDoesNotExist']]));

        self::assertSame([], SkillRegistry::all());
    }

    public function test_custom_skill_class_registered(): void
    {
        $this->registerConfigSkills((string) json_encode([['class' => JoomlaConfigSkillFixture::class]]));

        $all = SkillRegistry::all();

        self::assertCount(1, $all);
        self::assertSame('joomla-config-fixture', $all[0]->name());
    }

    public function test_multiple_inline_skills_all_registered(): void
    {
        $this->registerConfigSkills((string) json_encode([
            [
                'name' => 'joomla-articles',
                'description' => 'Article management tips',
                'tags' => ['articles'],
                'content' => 'Use com_content.',
            ],
            [
                'name' => 'joomla-users',
                'description' => 'User management tips',
                'tags' => ['users'],
                'content' => 'Use Joomla ACL.',
            ],
        ]));

        self::assertCount(2, SkillRegistry::all());
        self::assertTrue(SkillRegistry::has('joomla-articles'));
        self::assertTrue(SkillRegistry::has('joomla-users'));
    }

    public function test_invalid_entries_are_skipped_and_valid_ones_survive(): void
    {
        $this->registerConfigSkills((string) json_encode([
            'not-an-array',
            ['file' => '/nonexistent/skill.md'],
            ['class' => 'App\\Skills\\Missing'],
            [
                'name' => 'survivor',
                'description' => 'The only valid entry',
                'content' => 'Body.',
            ],
        ]));

        $all = SkillRegistry::all();

        self::assertCount(1, $all);
        self::assertSame('survivor', $all[0]->name());
    }
}

final class JoomlaConfigSkillFixture implements SkillInterface
{
    public function name(): string
    {
        return 'joomla-config-fixture';
    }

    public function description(): string
    {
        return 'A fixture skill registered by class name';
    }

    public function tags(): array
    {
        return ['fixture'];
    }

    public function content(): string
    {
        return 'Fixture skill content.';
    }
}
