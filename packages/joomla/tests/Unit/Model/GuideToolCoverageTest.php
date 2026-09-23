<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PhpClaw\Joomla\Component\Administrator\Engine\ToolBuilder;
use PhpClaw\Joomla\Component\Administrator\Model\GuideModel;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class GuideToolCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        PluginHelper::$plugin = false;
        Factory::$container = $this->containerWithDatabase();
    }

    protected function tearDown(): void
    {
        PluginHelper::$plugin = false;
        Factory::$container = null;
    }

    public function test_every_adapter_tool_carries_a_translated_label(): void
    {
        $langKeys = $this->constant('TOOL_LANG_KEYS');

        $untranslated = [];

        foreach ($this->registry()->all() as $tool) {
            if (! str_starts_with($tool::class, 'PhpClaw\\Joomla\\')) {
                continue;
            }

            if (! isset($langKeys[$tool->name()])) {
                $untranslated[] = $tool->name();
            }
        }

        self::assertSame(
            [],
            $untranslated,
            'A tool this adapter ships without a language key falls through to the untranslated '
            .'"extra" rows in the Guide.',
        );
    }

    public function test_the_two_tables_together_cover_all_fourteen_tools(): void
    {
        $coreNamespace = $this->constant('CORE_TOOL_NAMESPACE');

        $core = $joomla = 0;

        foreach ($this->registry()->all() as $tool) {
            str_starts_with($tool::class, $coreNamespace) ? $core++ : $joomla++;
        }

        self::assertSame(6, $joomla, 'The Joomla table must list six tools.');
        self::assertSame(8, $core, 'The core table must list eight tools, including zip_package.');
        self::assertSame(14, $core + $joomla, 'The Guide must account for every registered tool.');
    }

    public function test_zip_package_is_visible_to_the_core_table(): void
    {
        $coreNamespace = $this->constant('CORE_TOOL_NAMESPACE');

        $names = [];
        foreach ($this->registry()->all() as $tool) {
            if (str_starts_with($tool::class, $coreNamespace)) {
                $names[] = $tool->name();
            }
        }

        self::assertContains(
            'zip_package',
            $names,
            'zip_package ships registered but is not an auto-discovered default, so a Guide table '
            .'built from the discovery cache alone would omit it.',
        );
    }

    public function test_every_joomla_tool_has_a_language_key(): void
    {
        $langKeys = $this->constant('TOOL_LANG_KEYS');
        $coreNamespace = $this->constant('CORE_TOOL_NAMESPACE');

        foreach ($this->registry()->all() as $tool) {
            if (str_starts_with($tool::class, $coreNamespace)) {
                continue;
            }

            self::assertArrayHasKey($tool->name(), $langKeys);
        }
    }

    private function constant(string $name): mixed
    {
        return (new \ReflectionClass(GuideModel::class))->getConstant($name);
    }

    private function registry(): ToolRegistry
    {
        $config = PhpClawConfig::fromRegistry(EngineFactory::getPluginParams());
        $registry = new ToolRegistry;
        $registry->register(
            (new ToolBuilder)->build($config, applyProfile: false),
            $config->toolDeny,
            ToolBuilder::TOOL_GROUPS,
        );

        return $registry;
    }

    private function containerWithDatabase(): object
    {
        $db = $this->createMock(DatabaseInterface::class);

        return new class($db)
        {
            public function __construct(private readonly DatabaseInterface $db) {}

            public function get(string $id): DatabaseInterface
            {
                return $this->db;
            }
        };
    }
}
