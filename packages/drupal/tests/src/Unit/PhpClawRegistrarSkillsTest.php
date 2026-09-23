<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\DependencyInjection\ContainerInterface as DrupalContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use PhpClaw\Drupal\PhpClawRegistrar;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PHPUnit\Framework\TestCase;

final class PhpClawRegistrarSkillsTest extends TestCase
{
    private Connection $database;

    protected function setUp(): void
    {
        GuardRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();

        $this->database = $this->createMock(Connection::class);
    }

    protected function tearDown(): void
    {
        GuardRegistry::reset();
        HookRegistry::reset();
        MemoryRegistry::reset();
        SkillRegistry::reset();
    }

    private function runBootSkills(array $entries): void
    {
        $merged = [
            'skills' => $entries,
            'guards' => [],
            'hooks' => [],
            'guards_enabled' => [],
            'hooks_enabled' => [],
            'memory_config' => [],
        ];

        $immutable = $this->createMock(ImmutableConfig::class);
        $immutable->method('get')->willReturnCallback(
            static fn (string $key = '') => $key === '' ? $merged : ($merged[$key] ?? null)
        );

        $factory = $this->createMock(ConfigFactoryInterface::class);
        $factory->method('get')->willReturn($immutable);

        $cache = $this->createMock(CacheBackendInterface::class);
        $time = $this->createMock(TimeInterface::class);
        $container = $this->createMock(DrupalContainerInterface::class);
        $container->method('hasParameter')->willReturn(false);
        $container->method('getParameter')->willReturn([]);
        $container->method('get')->willReturn(null);

        $registrar = new PhpClawRegistrar($factory, $this->database, $cache, $time, $container);
        $registrar->boot();
    }

    public function test_empty_skills_config_registers_nothing(): void
    {
        $this->runBootSkills([]);

        $this->assertSame([], SkillRegistry::all());
    }

    public function test_inline_array_skill_registered_from_config(): void
    {
        $this->runBootSkills([[
            'name' => 'drupal-expert',
            'description' => 'Drupal best practices',
            'tags' => ['drupal', 'cms', 'entity'],
            'content' => 'Always use Drupal Entity API for content management.',
        ]]);

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('drupal-expert', $all[0]->name());
        $this->assertSame('Drupal best practices', $all[0]->description());
        $this->assertSame(['drupal', 'cms', 'entity'], $all[0]->tags());
    }

    public function test_file_skill_registered_from_config(): void
    {
        $path = sys_get_temp_dir().'/phpclaw-drupal-skill-'.uniqid().'.md';
        file_put_contents(
            $path,
            "---\nname: drupal-entities\ndescription: Drupal entity tips\ntags: [entities, drupal]\n---\nUse typed entity queries via entityTypeManager."
        );

        $this->runBootSkills([['file' => $path]]);

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('drupal-entities', $all[0]->name());

        unlink($path);
    }

    public function test_custom_skill_class_registered_from_config(): void
    {
        $skillClass = get_class(new class implements SkillInterface
        {
            public function name(): string
            {
                return 'custom-drupal-skill';
            }

            public function description(): string
            {
                return 'A custom Drupal skill';
            }

            public function tags(): array
            {
                return ['custom', 'drupal'];
            }

            public function content(): string
            {
                return 'Custom Drupal skill content.';
            }
        });

        $this->runBootSkills([['class' => $skillClass]]);

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('custom-drupal-skill', $all[0]->name());
    }

    public function test_invalid_skill_entries_skipped_silently(): void
    {
        $this->runBootSkills([
            'not-an-array',
            ['file' => '/nonexistent/drupal/skill.md'],
            ['class' => 'PhpClaw\\Drupal\\Skills\\DoesNotExist'],
            ['name' => 'missing-content'],
        ]);

        $this->assertSame([], SkillRegistry::all());
    }
}
