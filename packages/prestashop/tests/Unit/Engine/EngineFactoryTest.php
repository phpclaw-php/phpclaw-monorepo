<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Engine;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PhpClaw\PrestaShop\Engine\EngineFactory;
use PhpClaw\PrestaShop\Plugin;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EngineFactory::class)]
final class EngineFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $ref = new \ReflectionProperty(Plugin::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        parent::tearDown();
    }

    public function test_boot_registries_registers_ps_memory_drivers(): void
    {
        $factory = new EngineFactory([], [], null, 'ps_');
        $factory->bootRegistries();

        self::assertTrue(MemoryRegistry::has('ps_setting'));
        self::assertTrue(MemoryRegistry::has('ps_db'));
        self::assertTrue(MemoryRegistry::has('ps_router'));
    }

    public function test_plugin_holds_an_engine_factory_to_delegate_builds_to(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $ref = new \ReflectionProperty(Plugin::class, 'engineFactory');
        $ref->setAccessible(true);

        self::assertInstanceOf(EngineFactory::class, $ref->getValue($plugin));
    }

    public function test_save_settings_rebuilds_engine_factory(): void
    {
        $plugin = Plugin::getInstance(null, 'ps_');

        $factoryRef = new \ReflectionProperty(Plugin::class, 'engineFactory');
        $factoryRef->setAccessible(true);

        $before = $factoryRef->getValue($plugin);

        $plugin->saveSettings(['max_iterations' => 20]);

        $after = $factoryRef->getValue($plugin);

        self::assertNotSame($before, $after);
    }

    public function test_build_with_no_saved_settings_falls_back_to_the_environment_provider(): void
    {
        $factory = new EngineFactory([], [], $this->createMock(PsDbInterface::class), 'ps_');
        $factory->bootRegistries();

        $config = $factory->build()->config();

        self::assertSame(getenv('PHPCLAW_PROVIDER'), $config->providerName);
        self::assertSame(getenv('PHPCLAW_MODEL'), $config->model);
    }

    public function test_build_installs_cli_approval_gate(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $factory = new EngineFactory(['provider' => 'ollama', 'model' => 'qwen2.5:7b'], [], $db, 'ps_');
        $factory->bootRegistries();

        $engine = $factory->build();

        $configRef = new \ReflectionProperty($engine, 'config');
        $configRef->setAccessible(true);
        $config = $configRef->getValue($engine);

        self::assertInstanceOf(CliApprovalGate::class, $config->approvalGate);
    }

    public function test_file_write_tool_php_write_blocked_when_not_cli(): void
    {
        $flag = $this->allowPhpWriteFlag(isCli: false);

        self::assertFalse($flag, 'Non-CLI (chat/AJAX/REST) must never permit .php writes.');
    }

    public function test_file_write_tool_php_write_permitted_when_cli(): void
    {
        $flag = $this->allowPhpWriteFlag(isCli: true);

        self::assertTrue($flag, 'Interactive CLI must permit .php writes, gated by CliApprovalGate.');
    }

    public function test_guide_tools_forces_php_write_off_for_the_mcp_path_even_under_cli(): void
    {
        self::assertSame('cli', \PHP_SAPI, 'This test only proves the fix if PHPUnit itself runs under CLI, the exact condition that used to leak allowPhpWrite=true into MCP.');

        $db = $this->createMock(PsDbInterface::class);
        $plugin = Plugin::getInstance($db, 'ps_');

        $tools = $plugin->guideTools();

        $fileWrite = null;
        foreach ($tools as $tool) {
            if (method_exists($tool, 'name') && $tool->name() === 'file_write') {
                $fileWrite = $tool;
                break;
            }
        }

        self::assertNotNull($fileWrite, 'file_write must be present in the MCP tool list.');

        $flag = new \ReflectionProperty($fileWrite, 'allowPhpWrite');
        $flag->setAccessible(true);

        self::assertFalse((bool) $flag->getValue($fileWrite), 'MCP tool list must never allow PHP writes.');
    }

    public function test_deny_config_and_registry_together_remove_a_denied_tool_from_the_mcp_path(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $factory = new EngineFactory([], ['tool_deny' => ['ps_product']], $db, 'ps_');
        $factory->bootRegistries();

        $tools = $factory->buildTools(applyProfile: false, isCli: false);
        ['deny' => $deny, 'groups' => $groups] = $factory->denyConfig();

        $registry = new ToolRegistry;
        $registry->register($tools, $deny, $groups);

        self::assertFalse($registry->has('ps_product'), 'guideTools() must apply tool_deny for the MCP/Guide path.');
        self::assertTrue($registry->has('ps_order'));
    }

    private function allowPhpWriteFlag(bool $isCli): bool
    {
        $db = $this->createMock(PsDbInterface::class);
        $factory = new EngineFactory([], ['workspace_root' => sys_get_temp_dir()], $db, 'ps_');
        $factory->bootRegistries();

        $buildTools = new \ReflectionMethod($factory, 'buildTools');
        $buildTools->setAccessible(true);
        /** @var array<int, object> $tools */
        $tools = $buildTools->invoke($factory, true, $isCli);

        $fileWrite = null;
        foreach ($tools as $tool) {
            if (method_exists($tool, 'name') && $tool->name() === 'file_write') {
                $fileWrite = $tool;
                break;
            }
        }

        self::assertNotNull($fileWrite, 'file_write must be registered as a default core tool.');

        $flag = new \ReflectionProperty($fileWrite, 'allowPhpWrite');
        $flag->setAccessible(true);

        return (bool) $flag->getValue($fileWrite);
    }

    public function test_php_write_is_gated_on_the_declared_flag_not_on_php_sapi(): void
    {
        self::assertSame('cli', \PHP_SAPI, 'this test proves nothing unless the suite runs under CLI');

        $db = $this->createMock(PsDbInterface::class);
        $factory = new EngineFactory([], ['workspace_root' => sys_get_temp_dir()], $db, 'ps_');
        $factory->bootRegistries();

        self::assertFalse(
            $this->allowPhpWriteFromFactory($factory),
            'a factory that was not told it is a console session must not permit PHP writes',
        );
    }

    public function test_php_write_is_permitted_when_the_entrypoint_declares_a_console_session(): void
    {
        $db = $this->createMock(PsDbInterface::class);
        $factory = new EngineFactory([], ['workspace_root' => sys_get_temp_dir()], $db, 'ps_', 0, false, true);
        $factory->bootRegistries();

        self::assertTrue(
            $this->allowPhpWriteFromFactory($factory),
            'an entrypoint that declares itself a console session keeps PHP writes',
        );
    }

    private function allowPhpWriteFromFactory(EngineFactory $factory): bool
    {
        $buildTools = new \ReflectionMethod($factory, 'buildTools');
        $buildTools->setAccessible(true);
        /** @var array<int, object> $tools */
        $tools = $buildTools->invoke($factory, true, null);

        foreach ($tools as $tool) {
            if (method_exists($tool, 'name') && $tool->name() === 'file_write') {
                $flag = new \ReflectionProperty($tool, 'allowPhpWrite');
                $flag->setAccessible(true);

                return (bool) $flag->getValue($tool);
            }
        }

        self::fail('file_write must be registered as a default core tool.');
    }
}
