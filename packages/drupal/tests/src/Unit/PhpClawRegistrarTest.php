<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\DependencyInjection\ContainerInterface as DrupalContainerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use PhpClaw\AutoDiscovery\ComposerExtras;
use PhpClaw\Drupal\PhpClawRegistrar;
use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\RateLimitGuard;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\MemoryCatalogue;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Memory\RedisMemory;
use PhpClaw\Skills\Contracts\SkillInterface;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\HttpTool;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class PhpClawRegistrarTest extends TestCase
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

    private function buildRegistrar(
        array $config = [],
        ?EventDispatcherInterface $dispatcher = null,
    ): PhpClawRegistrar {
        $defaults = [
            'guards' => [],
            'hooks' => [],
            'skills' => [],
            'guards_enabled' => [],
            'hooks_enabled' => [],
            'memory_config' => [],
        ];
        $merged = array_merge($defaults, $config);

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

        $registrar = new PhpClawRegistrar($factory, $this->database, $cache, $time, $container, $dispatcher);
        $registrar->boot();

        return $registrar;
    }

    public function test_registrar_instantiates_without_error(): void
    {
        $registrar = $this->buildRegistrar();

        $this->assertInstanceOf(PhpClawRegistrar::class, $registrar);
    }

    public function test_boot_registers_database_memory_driver(): void
    {
        $this->buildRegistrar();

        $this->assertTrue(MemoryRegistry::has('database'));
    }

    public function test_boot_registers_file_memory_driver(): void
    {
        $this->buildRegistrar();

        $this->assertTrue(MemoryRegistry::has('file'));
    }

    public function test_boot_registers_cache_memory_driver(): void
    {
        $this->buildRegistrar();

        $this->assertTrue(MemoryRegistry::has('cache'));
    }

    public function test_boot_registers_default_guards_when_none_configured(): void
    {
        $this->buildRegistrar(['guards' => []]);

        $this->assertGreaterThanOrEqual(6, GuardRegistry::count());
    }

    public function test_boot_skips_guard_entry_missing_class_key(): void
    {
        $guardCount = GuardRegistry::count();

        $this->buildRegistrar([
            'guards' => [
                ['priority' => 5],
            ],
        ]);

        $this->assertGreaterThanOrEqual($guardCount, GuardRegistry::count());
    }

    public function test_boot_skips_guard_entry_with_non_string_class(): void
    {
        $this->buildRegistrar([
            'guards' => [
                ['class' => 42, 'priority' => 5],
            ],
        ]);

        $this->assertGreaterThanOrEqual(6, GuardRegistry::count());
    }

    public function test_boot_skips_nonexistent_guard_class(): void
    {
        $this->buildRegistrar([
            'guards' => [
                ['class' => 'PhpClaw\\Guards\\DoesNotExistGuard', 'priority' => 5],
            ],
        ]);

        $this->assertGreaterThanOrEqual(6, GuardRegistry::count());
    }

    public function test_boot_skips_guard_class_not_implementing_guard_interface(): void
    {
        $this->buildRegistrar([
            'guards' => [
                ['class' => \stdClass::class, 'priority' => 5],
            ],
        ]);

        $this->assertGreaterThanOrEqual(6, GuardRegistry::count());
    }

    public function test_boot_registers_valid_guard_from_config(): void
    {
        GuardRegistry::reset();
        $this->buildRegistrar(['guards' => []]);
        $defaultCount = GuardRegistry::count();

        GuardRegistry::reset();
        $this->buildRegistrar([
            'guards' => [
                ['class' => RateLimitGuard::class, 'priority' => 99],
            ],
        ]);

        $this->assertSame($defaultCount + 1, GuardRegistry::count());
        $this->assertTrue(GuardRegistry::hasClass(RateLimitGuard::class));
    }

    public function test_boot_hooks_skips_non_array_entry(): void
    {
        $this->buildRegistrar([
            'hooks' => ['not-an-array'],
        ]);

        $this->assertSame(0, HookRegistry::count());
    }

    public function test_boot_hooks_skips_entry_missing_event_key(): void
    {
        $this->buildRegistrar([
            'hooks' => [
                ['handler' => static fn () => null],
            ],
        ]);

        $this->assertSame(0, HookRegistry::count());
    }

    public function test_boot_hooks_skips_entry_missing_handler_key(): void
    {
        $this->buildRegistrar([
            'hooks' => [
                ['event' => 'agent.before'],
            ],
        ]);

        $this->assertSame(0, HookRegistry::count());
    }

    public function test_boot_hooks_skips_entry_with_empty_event_string(): void
    {
        $this->buildRegistrar([
            'hooks' => [
                ['event' => '', 'handler' => static fn () => null],
            ],
        ]);

        $this->assertSame(0, HookRegistry::count());
    }

    public function test_boot_hooks_skips_non_callable_handler(): void
    {
        $this->buildRegistrar([
            'hooks' => [
                ['event' => 'agent.before', 'handler' => 'not_a_callable_string'],
            ],
        ]);

        $this->assertSame(0, HookRegistry::count());
    }

    public function test_boot_hooks_registers_valid_hook_entry(): void
    {
        $called = false;
        $handler = static function (array $ctx) use (&$called): void {
            $called = true;
        };

        $this->buildRegistrar([
            'hooks' => [
                ['event' => 'agent.before', 'handler' => $handler, 'priority' => 5],
            ],
        ]);

        $this->assertSame(1, HookRegistry::count('agent.before'));
    }

    public function test_boot_hooks_registers_valid_hook_without_priority(): void
    {
        $handler = static function (array $ctx): void {};

        $this->buildRegistrar([
            'hooks' => [
                ['event' => 'agent.after', 'handler' => $handler],
            ],
        ]);

        $this->assertSame(1, HookRegistry::count('agent.after'));
    }

    public function test_boot_without_event_dispatcher_does_not_register_bridge(): void
    {
        $registrar = $this->buildRegistrar(dispatcher: null);

        $this->assertInstanceOf(PhpClawRegistrar::class, $registrar);
    }

    public function test_boot_with_event_dispatcher_registers_bridge_hooks(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->buildRegistrar(dispatcher: $dispatcher);

        $this->assertGreaterThan(0, HookRegistry::count());
    }

    public function test_boot_multiple_invalid_hook_entries_all_skipped(): void
    {
        $this->buildRegistrar([
            'hooks' => [
                'string-not-array',
                ['event' => 123, 'handler' => static fn () => null],
                ['event' => 'agent.before'],
                ['handler' => static fn () => null],
            ],
        ]);

        $this->assertSame(0, HookRegistry::count());
    }

    public function test_boot_registers_database_file_and_cache_drivers_all_at_once(): void
    {
        $this->buildRegistrar();

        $drivers = MemoryRegistry::drivers();

        $this->assertContains('database', $drivers);
        $this->assertContains('file', $drivers);
        $this->assertContains('cache', $drivers);
    }

    public function test_boot_guard_entry_non_array_is_skipped(): void
    {
        $this->buildRegistrar([
            'guards' => ['not-an-array'],
        ]);

        $this->assertGreaterThanOrEqual(6, GuardRegistry::count());
    }

    public function test_boot_skills_inline_array_skill_registered(): void
    {
        $this->buildRegistrar([
            'skills' => [[
                'name' => 'drupal-tips',
                'description' => 'Drupal developer tips',
                'tags' => ['drupal'],
                'content' => 'Always use the Entity API.',
            ]],
        ]);

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('drupal-tips', $all[0]->name());
    }

    public function test_boot_skills_file_skill_registered(): void
    {
        $path = sys_get_temp_dir().'/phpclaw-reg-skill-'.uniqid().'.md';
        file_put_contents(
            $path,
            "---\nname: reg-file-skill\ndescription: Test\ntags: [test]\n---\nContent here."
        );

        $this->buildRegistrar(['skills' => [['file' => $path]]]);

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('reg-file-skill', $all[0]->name());

        unlink($path);
    }

    public function test_boot_skills_bad_file_path_is_skipped(): void
    {
        $this->buildRegistrar([
            'skills' => [['file' => '/nonexistent/path/skill.md']],
        ]);

        $this->assertSame([], SkillRegistry::all());
    }

    public function test_boot_skills_custom_class_registered(): void
    {
        $skillClass = get_class(new class implements SkillInterface
        {
            public function name(): string
            {
                return 'registrar-custom';
            }

            public function description(): string
            {
                return 'Custom skill via registrar';
            }

            public function tags(): array
            {
                return ['test'];
            }

            public function content(): string
            {
                return 'Custom content.';
            }
        });

        $this->buildRegistrar(['skills' => [['class' => $skillClass]]]);

        $all = SkillRegistry::all();
        $this->assertCount(1, $all);
        $this->assertSame('registrar-custom', $all[0]->name());
    }

    public function test_boot_skills_class_not_implementing_interface_is_skipped(): void
    {
        $this->buildRegistrar([
            'skills' => [['class' => \stdClass::class]],
        ]);

        $this->assertSame([], SkillRegistry::all());
    }

    public function test_boot_skills_nonexistent_class_is_skipped(): void
    {
        $this->buildRegistrar([
            'skills' => [['class' => 'PhpClaw\\Drupal\\Skills\\NonExistent']],
        ]);

        $this->assertSame([], SkillRegistry::all());
    }

    public function test_boot_skills_non_array_entry_is_skipped(): void
    {
        $this->buildRegistrar([
            'skills' => ['not-an-array'],
        ]);

        $this->assertSame([], SkillRegistry::all());
    }

    public function test_boot_skills_incomplete_inline_entry_is_skipped(): void
    {
        $this->buildRegistrar([
            'skills' => [['name' => 'only-name']],
        ]);

        $this->assertSame([], SkillRegistry::all());
    }

    public function test_boot_autodiscovers_composer_extras_capability(): void
    {
        ComposerExtras::withTestPayload([
            'fake/phpclaw-memory-mongo' => [
                'memory' => [
                    'drupal-bootstrap-test' => [
                        'label' => 'Drupal Bootstrap Test Memory',
                        'class' => RedisMemory::class,
                    ],
                ],
            ],
        ]);
        MemoryCatalogue::reset();

        $this->buildRegistrar();

        $entry = MemoryCatalogue::find('drupal-bootstrap-test');
        self::assertNotNull($entry, 'Registrar boot should trigger Bootstrap::boot() discovery.');
        self::assertSame('Drupal Bootstrap Test Memory', $entry['label']);

        MemoryCatalogue::reset();
        ComposerExtras::reset();
    }

    public function test_boot_autodiscovers_extras_tool(): void
    {
        ComposerExtras::withTestPayload([
            'fake/phpclaw-extra-tool' => [
                'tools' => [
                    'drupal-extras-tool-test' => [
                        'label' => 'Drupal Extras Tool Test',
                        'class' => HttpTool::class,
                    ],
                ],
            ],
        ]);

        $registrar = $this->buildRegistrar();

        $classes = array_map(
            static fn (object $tool): string => $tool::class,
            $registrar->getExternalTools(),
        );
        self::assertContains(
            HttpTool::class,
            $classes,
            'Extras-package tool should be collected into the external tool list.',
        );

        ComposerExtras::reset();
    }

    public function test_second_boot_call_does_not_re_register_memory_drivers(): void
    {
        $registrar = $this->buildRegistrar();

        $countAfterFirstBoot = count(MemoryRegistry::drivers());
        $this->assertGreaterThanOrEqual(3, $countAfterFirstBoot);

        $registrar->boot();

        $this->assertSame($countAfterFirstBoot, count(MemoryRegistry::drivers()));
    }

    public function test_second_boot_call_does_not_re_register_guards(): void
    {
        $registrar = $this->buildRegistrar();

        $countAfterFirstBoot = GuardRegistry::count();
        $this->assertGreaterThanOrEqual(6, $countAfterFirstBoot);

        $registrar->boot();

        $this->assertSame($countAfterFirstBoot, GuardRegistry::count());
    }
}
