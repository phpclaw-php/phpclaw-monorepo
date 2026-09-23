<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Tools;

use PhpClaw\Joomla\Component\Administrator\Tools\JoomlaUserTool;
use PhpClaw\Joomla\Tests\Support\MockDatabase;
use PhpClaw\Joomla\Tests\Support\StubsJoomlaAccess;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ToolCapabilityFlatteningTest extends TestCase
{
    use StubsJoomlaAccess;

    private const ACTION = 'phpclaw.chat.use';

    private const ASSET = 'com_phpclaw';

    private const EXPECTED_TOOL_COUNT = 6;

    private const RETIRED_ACTIONS = ['core.manage', 'core.admin'];

    private const RETIRED_ASSETS = ['com_content', 'com_users', 'com_categories', 'com_installer'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->grantJoomlaAccess();
    }

    protected function tearDown(): void
    {
        $this->clearJoomlaAccess();
        parent::tearDown();
    }

    public function test_every_tool_declares_the_same_action(): void
    {
        $actions = [];

        foreach ($this->tools() as $tool) {
            $actions[] = $tool->requiredCapability();
        }

        $this->assertCount(1, array_unique($actions));
        $this->assertSame([self::ACTION], array_values(array_unique($actions)));
    }

    public function test_every_tool_declares_the_same_asset(): void
    {
        $assets = [];

        foreach ($this->tools() as $tool) {
            $method = new ReflectionMethod($tool, 'requiredAsset');
            $method->setAccessible(true);
            $assets[] = $method->invoke($tool);
        }

        $this->assertCount(1, array_unique($assets));
        $this->assertSame([self::ASSET], array_values(array_unique($assets)));
    }

    public function test_no_retired_action_or_asset_survives(): void
    {
        foreach ($this->toolFiles() as $file) {
            $source = (string) file_get_contents($file);

            foreach (self::RETIRED_ACTIONS as $action) {
                $this->assertStringNotContainsString(
                    "REQUIRED_ACTION = '".$action."'",
                    $source,
                    basename($file).' still declares the retired action '.$action,
                );
            }

            foreach (self::RETIRED_ASSETS as $asset) {
                $this->assertStringNotContainsString(
                    "REQUIRED_ASSET = '".$asset."'",
                    $source,
                    basename($file).' still declares the retired asset '.$asset,
                );
            }
        }
    }

    public function test_the_tool_set_is_discovered_not_listed(): void
    {
        $this->assertCount(self::EXPECTED_TOOL_COUNT, $this->toolFiles());
    }

    public function test_describe_reports_the_flattened_pair(): void
    {
        foreach (['JoomlaArticleTool', 'JoomlaUserTool', 'JoomlaExtensionTool'] as $name) {
            $fqcn = 'PhpClaw\\Joomla\\Component\\Administrator\\Tools\\'.$name;

            $result = json_decode(
                (new $fqcn(MockDatabase::raw($this)))->execute(['schema' => true]),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertSame(self::ACTION, $result['data']['joomla_action'], $name);
            $this->assertSame(self::ASSET, $result['data']['joomla_asset'], $name);
        }
    }

    public function test_the_forbidden_path_names_the_flattened_action(): void
    {
        $this->denyJoomlaAccess();

        $json = (new JoomlaUserTool(MockDatabase::raw($this)))->execute([]);

        $this->assertForbiddenEnvelope($json);
        $this->assertStringContainsString(self::ACTION, $json);
    }

    private function tools(): array
    {
        $tools = [];

        foreach ($this->toolFiles() as $file) {
            $fqcn = 'PhpClaw\\Joomla\\Component\\Administrator\\Tools\\'.basename($file, '.php');
            $tools[] = (new ReflectionClass($fqcn))->newInstanceWithoutConstructor();
        }

        return $tools;
    }

    private function toolFiles(): array
    {
        $files = [];

        foreach ((array) glob(__DIR__.'/../../../component/src/Tools/*Tool.php') as $file) {
            if (str_starts_with(basename((string) $file), 'Abstract')) {
                continue;
            }

            $files[] = (string) $file;
        }

        return $files;
    }
}
