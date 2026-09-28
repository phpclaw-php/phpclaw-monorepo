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
use PhpClaw\Tools\Concerns\FileReadLog;
use PhpClaw\Tools\Contracts\MutatingToolInterface;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\ShellTool;
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
        FileReadLog::reset();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->workspace)) {
            array_map('unlink', glob($this->workspace.'/*') ?: []);
            rmdir($this->workspace);
        }

        FileReadLog::reset();
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
        $names = $this->toolNames(['provider' => 'ollama']);

        self::assertNotContains(
            'file_write',
            $names,
            'file_write is opt-in; registeredTools() must not expose a PHP-write surface by default',
        );
    }

    public function test_php_file_write_is_permitted_when_cli(): void
    {
        $tool = $this->configuredFileWriteTool(allowPhpWrite: true);

        $result = $tool->execute(['path' => 'builder-demo.php', 'content' => "<?php\necho 1;\n"]);

        self::assertStringContainsString('Written', $result);
    }

    public function test_php_file_write_is_blocked_when_not_cli(): void
    {
        $tool = $this->configuredFileWriteTool(allowPhpWrite: false);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'builder-demo.php', 'content' => "<?php\necho 1;\n"]);
    }

    public function test_core_tool_not_shadowed_by_config_less_duplicate(): void
    {
        $tools = EngineFactory::registeredTools(
            ['workspace_root' => $this->workspace],
            ['provider' => 'anthropic'],
            [FileReadTool::class],
        );

        $fileReads = array_values(array_filter($tools, static fn (object $t): bool => $t->name() === 'file_read'));

        self::assertCount(1, $fileReads, 'file_read must not be duplicated by extra-tool discovery.');
        self::assertSame(realpath($this->workspace), $fileReads[0]->workspaceRoot(), 'The configured core file_read must win, not the config-less duplicate.');
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

    private function readPrivate(object $object, string $property): mixed
    {
        $prop = (new \ReflectionObject($object))->getProperty($property);

        return $prop->getValue($object);
    }

    private function configuredFileWriteTool(bool $allowPhpWrite): FileWriteTool
    {
        return new FileWriteTool($this->workspace, $allowPhpWrite);
    }

    public function test_extra_shell_tool_class_gets_the_configured_allowlist(): void
    {
        $tools = EngineFactory::registeredTools(
            ['workspace_root' => $this->workspace, 'shell_allowlist' => ['ls', 'pwd']],
            ['provider' => 'anthropic'],
            [ShellTool::class],
        );

        $shells = array_values(array_filter($tools, static fn (object $t): bool => $t->name() === 'shell_exec'));

        self::assertCount(1, $shells, 'shell_exec must be added by the phpclaw_extra_tools filter');
        self::assertSame(
            ['ls', 'pwd'],
            $shells[0]->allowlist(),
            'a ShellTool added through the filter must use the adapter shell_allowlist, not the core default',
        );
    }

    public function test_extra_shell_tool_class_does_not_get_core_default_allowlist(): void
    {
        $tools = EngineFactory::registeredTools(
            ['workspace_root' => $this->workspace, 'shell_allowlist' => ['cat']],
            ['provider' => 'anthropic'],
            [ShellTool::class],
        );

        $shells = array_values(array_filter($tools, static fn (object $t): bool => $t->name() === 'shell_exec'));

        self::assertNotContains(
            'df',
            $shells[0]->allowlist(),
            'the core default allowlist ("df" among others) must not leak through when a shell_allowlist is configured',
        );
    }

    public function test_extra_file_write_tool_class_gets_the_configured_workspace_root(): void
    {
        $tools = EngineFactory::registeredTools(
            ['workspace_root' => $this->workspace],
            ['provider' => 'anthropic'],
            [FileWriteTool::class],
        );

        $writers = array_values(array_filter($tools, static fn (object $t): bool => $t->name() === 'file_write'));

        self::assertCount(1, $writers, 'file_write must be added by the phpclaw_extra_tools filter');
        self::assertSame(
            realpath($this->workspace),
            $writers[0]->workspaceRoot(),
            'a FileWriteTool added through the filter must use the adapter workspace_root, not its own default',
        );
    }

    public function test_extra_file_write_tool_class_allows_php_write_when_cli(): void
    {
        $tool = $this->extraFileWriteTool(allowPhpWrite: true);

        $result = $tool->execute(['path' => 'extra-demo.php', 'content' => "<?php\necho 1;\n"]);

        self::assertStringContainsString('Written', $result);
        self::assertFileExists($this->workspace.'/extra-demo.php');
    }

    public function test_extra_file_write_tool_class_refuses_php_write_when_not_cli(): void
    {
        $tool = $this->extraFileWriteTool(allowPhpWrite: false);

        $this->expectException(ToolException::class);

        $tool->execute(['path' => 'extra-demo.php', 'content' => "<?php\necho 1;\n"]);
    }

    public function test_extra_file_edit_tool_class_operates_on_the_configured_workspace_root(): void
    {
        $filePath = $this->workspace.'/edit-demo.txt';
        file_put_contents($filePath, 'hello world');

        $tools = EngineFactory::registeredTools(
            ['workspace_root' => $this->workspace],
            ['provider' => 'anthropic'],
            [FileEditTool::class],
        );

        $editors = array_values(array_filter($tools, static fn (object $t): bool => $t->name() === 'file_edit'));
        self::assertCount(1, $editors, 'file_edit must be added by the phpclaw_extra_tools filter');

        FileReadLog::markRead((string) realpath($this->workspace), (string) realpath($filePath));

        $editors[0]->execute([
            'path' => 'edit-demo.txt',
            'old_str' => 'hello world',
            'new_str' => 'hello workspace',
        ]);

        self::assertSame(
            'hello workspace',
            file_get_contents($filePath),
            'a FileEditTool added through the filter must resolve paths against the adapter workspace_root',
        );
    }

    private function extraFileWriteTool(bool $allowPhpWrite): FileWriteTool
    {
        $tools = $this->invokeBuildTools($allowPhpWrite);

        $writers = array_values(array_filter($tools, static fn (object $t): bool => $t->name() === 'file_write'));

        return $writers[0];
    }

    private function invokeBuildTools(bool $allowPhpWrite): array
    {
        $ref = new \ReflectionClass(EngineFactory::class);
        $m = $ref->getMethod('buildTools');
        $m->setAccessible(true);

        return $m->invoke(
            null,
            $this->workspace,
            [],
            ['provider' => 'anthropic'],
            [FileWriteTool::class],
            false,
            $allowPhpWrite,
        );
    }
}
