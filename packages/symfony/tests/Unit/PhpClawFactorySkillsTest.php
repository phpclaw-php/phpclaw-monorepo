<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Symfony\PhpClawFactory;
use PHPUnit\Framework\TestCase;

final class PhpClawFactorySkillsTest extends TestCase
{
    protected function setUp(): void
    {
        SkillRegistry::reset();
        putenv('OPENAI_API_KEY=sk-test-skills');
    }

    protected function tearDown(): void
    {
        SkillRegistry::reset();
        putenv('OPENAI_API_KEY');
    }

    public function test_empty_skills_config_registers_nothing(): void
    {
        $factory = $this->makeFactory(skills: []);
        $factory->create();

        $this->assertSame([], SkillRegistry::all());
    }

    public function test_inline_array_skill_registered_from_config(): void
    {
        SkillRegistry::reset();

        $factory = $this->makeFactory(skills: [[
            'name' => 'symfony-expert',
            'description' => 'Symfony best practices',
            'tags' => ['symfony', 'doctrine'],
            'content' => 'Use Doctrine ORM for all database operations in Symfony.',
        ]]);
        $factory->create();

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('symfony-expert', $all[0]->name());
    }

    public function test_file_skill_registered_from_config(): void
    {
        SkillRegistry::reset();

        $path = sys_get_temp_dir().'/phpclaw-sf-skill-'.uniqid().'.md';
        file_put_contents($path, "---\nname: sf-file-skill\ndescription: From file\ntags: [symfony]\n---\nSymfony file skill content.");

        $factory = $this->makeFactory(skills: [['file' => $path]]);
        $factory->create();

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('sf-file-skill', $all[0]->name());

        unlink($path);
    }

    public function test_class_skill_registered_from_config(): void
    {
        SkillRegistry::reset();

        $skillClass = get_class(new class implements SkillInterface
        {
            public function name(): string
            {
                return 'symfony-custom-skill';
            }

            public function description(): string
            {
                return 'Custom skill for Symfony';
            }

            public function tags(): array
            {
                return ['symfony', 'custom'];
            }

            public function content(): string
            {
                return 'Symfony custom content.';
            }
        });

        $factory = $this->makeFactory(skills: [['class' => $skillClass]]);
        $factory->create();

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('symfony-custom-skill', $all[0]->name());
    }

    public function test_invalid_skill_entries_skipped_silently(): void
    {
        SkillRegistry::reset();

        $factory = $this->makeFactory(skills: [
            'not-an-array',
            ['file' => '/nonexistent/skill.md'],
            ['class' => 'App\\Skills\\DoesNotExist'],
            ['name' => 'missing-content'],
        ]);
        $factory->create();

        $this->assertSame([], SkillRegistry::all());
    }

    private function makeFactory(array $skills = []): PhpClawFactory
    {
        return new PhpClawFactory(
            apiKey: '',
            provider: 'openai',
            model: '',
            storeMessages: false,
            maxIterations: 10,
            shellAllowlist: ['ls', 'php'],
            tools: [],
            memory: new ArrayMemory,
            workspaceRoot: sys_get_temp_dir(),
            systemPrompt: '',
            maxTokens: 0,
            promptCache: false,
            thinkingBudget: 0,
            skills: $skills,
        );
    }
}
