<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineFactory;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PhpClaw\Joomla\Component\Administrator\Engine\ToolBuilder;
use PhpClaw\Joomla\Component\Administrator\Model\AboutModel;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;

final class AboutModelTest extends TestCase
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

    public function test_the_built_in_count_covers_every_tool_the_package_registers(): void
    {
        $config = PhpClawConfig::fromRegistry(EngineFactory::getPluginParams());
        $tools = (new ToolBuilder)->build($config, applyProfile: false);
        $names = array_map(static fn (object $tool): string => $tool->name(), $tools);
        sort($names);

        self::assertSame(
            ['code_search', 'file_read', 'joomla_articles', 'joomla_categories', 'joomla_database_query', 'joomla_extensions', 'joomla_users', 'joomla_zip_extension', 'project_info', 'zip_package'],
            $names,
        );
        self::assertSame(count($names), $this->countBuiltIn($tools));
    }

    public function test_a_tool_added_by_another_extension_is_not_counted(): void
    {
        $config = PhpClawConfig::fromRegistry(EngineFactory::getPluginParams());
        $tools = (new ToolBuilder)->build($config, applyProfile: false);
        $before = $this->countBuiltIn($tools);

        $tools[] = new class implements ToolInterface
        {
            public function name(): string
            {
                return 'third_party_tool';
            }

            public function description(): string
            {
                return 'Added by another extension.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                return '';
            }
        };

        self::assertSame($before, $this->countBuiltIn($tools));
    }

    public function test_the_title_carries_a_count_when_the_tools_can_be_listed(): void
    {
        self::assertSame('COM_PHPCLAW_FEAT_TOOLS_TITLE_COUNT', $this->model()->getToolsTitle());
    }

    public function test_the_title_has_no_number_when_the_tools_cannot_be_listed(): void
    {
        Factory::$container = null;

        self::assertSame('COM_PHPCLAW_FEAT_TOOLS_TITLE', $this->model()->getToolsTitle());
    }

    private function countBuiltIn(array $tools): int
    {
        return (new \ReflectionMethod(AboutModel::class, 'countBuiltIn'))->invoke(null, $tools);
    }

    private function model(): AboutModel
    {
        return (new \ReflectionClass(AboutModel::class))->newInstanceWithoutConstructor();
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
