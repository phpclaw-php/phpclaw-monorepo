<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;

final class SkillResolverDelegationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-api-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        SkillRegistry::reset();
    }

    public function test_canonical_feature_inline_file_and_class_entries_all_resolve(): void
    {
        SkillRegistry::reset();

        $skillClass = get_class(new class implements SkillInterface
        {
            public function name(): string
            {
                return 'class-skill';
            }

            public function description(): string
            {
                return 'A class skill';
            }

            public function tags(): array
            {
                return [];
            }

            public function content(): string
            {
                return 'Class skill content.';
            }
        });

        $path = sys_get_temp_dir().'/phpclaw-skill-delegation-'.uniqid().'.md';
        file_put_contents($path, "---\nname: file-skill\ndescription: File skill\ntags: [test]\n---\nFile content.");

        $this->app['config']->set('phpclaw.skills', [
            ['name' => 'inline-skill', 'description' => 'Inline skill', 'tags' => ['inline'], 'content' => 'Inline content.'],
            ['file' => $path],
            ['class' => $skillClass],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $all = SkillRegistry::all();
        $names = array_map(fn (SkillInterface $s): string => $s->name(), $all);

        $this->assertContains('inline-skill', $names);
        $this->assertContains('file-skill', $names);
        $this->assertContains('class-skill', $names);

        unlink($path);
    }

    public function test_mixed_entries_valid_kept_malformed_skipped(): void
    {
        SkillRegistry::reset();

        $this->app['config']->set('phpclaw.skills', [
            'not-an-array',
            ['file' => '/nonexistent/path/skill.md'],
            ['class' => 'App\\Skills\\DoesNotExist'],
            ['name' => 'missing-content'],
            ['name' => 'good-skill', 'description' => 'Good', 'tags' => [], 'content' => 'Good content.'],
        ]);

        $provider = new PhpClawServiceProvider($this->app);
        $provider->register();
        $provider->boot();

        $all = SkillRegistry::all();
        $names = array_map(fn (SkillInterface $s): string => $s->name(), $all);

        $this->assertContains('good-skill', $names, 'The valid inline skill should resolve');
        $this->assertNotContains('missing-content', $names, 'Malformed config entries must be skipped');
    }

    public function test_engine_factory_resolves_every_skill_entry_shape_not_just_inline(): void
    {
        SkillRegistry::reset();

        $skillClass = get_class(new class implements SkillInterface
        {
            public function name(): string
            {
                return 'factory-class-skill';
            }

            public function description(): string
            {
                return 'A class skill';
            }

            public function tags(): array
            {
                return [];
            }

            public function content(): string
            {
                return 'Class skill content.';
            }
        });

        $path = sys_get_temp_dir().'/phpclaw-factory-delegation-'.uniqid().'.md';
        file_put_contents($path, "---\nname: factory-file-skill\ndescription: File skill\ntags: [test]\n---\nFile content.");

        $this->app['config']->set('phpclaw.skills', [
            ['name' => 'factory-inline-skill', 'description' => 'Inline', 'tags' => ['inline'], 'content' => 'Inline content.'],
            ['file' => $path],
            ['class' => $skillClass],
        ]);

        $method = new \ReflectionMethod(EngineFactory::class, 'resolveSkills');
        $method->setAccessible(true);
        /** @var SkillInterface[] $resolved */
        $resolved = $method->invoke(null);

        $names = array_map(static fn (SkillInterface $s): string => $s->name(), $resolved);

        unlink($path);

        self::assertContains('factory-inline-skill', $names);
        self::assertContains(
            'factory-file-skill',
            $names,
            'EngineFactory must delegate to SkillResolver::resolve(): a file entry only resolves there. '.
            'Constructing ArraySkill inline would handle the inline shape and silently drop this one.',
        );
        self::assertContains(
            'factory-class-skill',
            $names,
            'EngineFactory must delegate to SkillResolver::resolve(): a class entry only resolves there.',
        );
    }
}
