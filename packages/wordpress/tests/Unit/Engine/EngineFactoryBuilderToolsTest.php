<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Engine;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Claw as PhpClaw;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\MemoryRegistry;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\WordPress\Engine\EngineFactory;
use PhpClaw\WordPress\Tools\WpZipBuilderTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EngineFactory::class)]
final class EngineFactoryBuilderToolsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (! MemoryRegistry::has('wpdb_router')) {
            MemoryRegistry::register('wpdb_router', fn () => new ArrayMemory);
        }

        $this->workspace = sys_get_temp_dir().'/phpclaw_ef_'.uniqid();
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspace)) {
            array_map('unlink', glob($this->workspace.'/*') ?: []);
            rmdir($this->workspace);
        }

        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_builder_tools_always_present(): void
    {
        $names = $this->toolNames(['provider' => 'ollama']);

        self::assertContains('zip_package', $names);
        self::assertContains('wp_zip_plugin', $names);
    }

    public function test_php_file_write_is_blocked_via_registered_tools(): void
    {
        $tool = $this->fileWriteTool(['provider' => 'ollama']);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'builder-demo.php', 'content' => "<?php\necho 1;\n"]);
    }

    public function test_php_file_write_is_permitted_when_cli(): void
    {
        $tool = $this->fileWriteToolViaBuildTools(isCli: true);

        $result = $tool->execute(['path' => 'builder-demo.php', 'content' => "<?php\necho 1;\n"]);

        self::assertStringContainsString('Written', $result);
    }

    public function test_php_file_write_is_blocked_when_not_cli(): void
    {
        $tool = $this->fileWriteToolViaBuildTools(isCli: false);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'builder-demo.php', 'content' => "<?php\necho 1;\n"]);
    }

    public function test_core_tool_not_shadowed_by_config_less_duplicate(): void
    {
        $tools = EngineFactory::registeredTools(
            ['workspace_root' => $this->workspace],
            ['provider' => 'anthropic'],
            [FileWriteTool::class],
        );

        $fileWrites = array_values(array_filter($tools, static fn (object $t): bool => $t->name() === 'file_write'));

        self::assertCount(1, $fileWrites, 'file_write must not be duplicated by extra-tool discovery.');
        self::assertSame(realpath($this->workspace), $fileWrites[0]->workspaceRoot(), 'The configured core file_write must win, not the config-less duplicate.');
    }

    public function test_build_installs_approval_gate(): void
    {
        $engine = EngineFactory::build(
            config: ['workspace_root' => $this->workspace],
            saved: ['provider' => 'ollama'],
        );

        self::assertInstanceOf(PhpClaw::class, $engine);

        $config = $this->readPrivate($engine, 'config');
        $gate = $this->readPrivate($config, 'approvalGate');

        self::assertInstanceOf(CliApprovalGate::class, $gate);
    }

    public function test_wp_zip_plugin_is_gated_via_mutating_marker(): void
    {
        self::assertInstanceOf(
            MutatingToolInterface::class,
            new WpZipBuilderTool($this->workspace),
            'wp_zip_plugin must implement MutatingToolInterface so the core gate requires approval.',
        );
    }

    private function toolNames(array $saved): array
    {
        $tools = EngineFactory::registeredTools(['workspace_root' => $this->workspace], $saved);

        return array_map(static fn (object $t): string => $t->name(), $tools);
    }

    private function fileWriteTool(array $saved): ToolInterface
    {
        $tools = EngineFactory::registeredTools(['workspace_root' => $this->workspace], $saved);

        foreach ($tools as $tool) {
            if ($tool->name() === 'file_write') {
                return $tool;
            }
        }

        self::fail('file_write tool was not registered.');
    }

    private function readPrivate(object $object, string $property): mixed
    {
        $prop = (new \ReflectionObject($object))->getProperty($property);

        return $prop->getValue($object);
    }

    private function fileWriteToolViaBuildTools(bool $isCli): ToolInterface
    {
        $method = new \ReflectionMethod(EngineFactory::class, 'buildTools');
        $method->setAccessible(true);

        $tools = $method->invoke(
            null,
            $this->workspace,
            [],
            [],
            [],
            false,
            $isCli,
        );

        foreach ($tools as $tool) {
            if ($tool->name() === 'file_write') {
                return $tool;
            }
        }

        self::fail('file_write tool was not registered.');
    }
}
