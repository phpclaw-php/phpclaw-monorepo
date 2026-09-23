<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Memory\PrivacyAwareMemory;
use PhpClaw\Providers\OpenAIProvider;
use PhpClaw\Symfony\Extension\PhpClawExtensions;
use PhpClaw\Symfony\PhpClawFactory;
use PhpClaw\Symfony\Tools\LogTool;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileReadTool;
use PhpClaw\Tools\FileWriteTool;
use PhpClaw\Tools\ShellTool;
use PHPUnit\Framework\TestCase;

final class PhpClawFactoryCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('OPENAI_API_KEY=sk-test-cov');
    }

    protected function tearDown(): void
    {
        putenv('OPENAI_API_KEY');
    }

    public function test_custom_provider_override_built_when_base_url_is_https(): void
    {
        $config = $this->makeFactory(
            provider: 'custom',
            baseUrl: 'https://my-openai-proxy.example.com/v1',
        )->create()->config();

        self::assertInstanceOf(OpenAIProvider::class, $config->providerOverride);
        self::assertSame('custom', $config->providerOverride->name());
        self::assertSame('https://my-openai-proxy.example.com/v1', $config->providerOverride->endpoint());
    }

    public function test_custom_provider_override_returns_null_for_external_http(): void
    {
        $factory = $this->makeFactory(provider: 'custom', baseUrl: 'http://api.evil.example.com/v1');
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('buildCustomProviderOverride');
        $override = $method->invoke($factory);

        self::assertNull($override);
    }

    public function test_custom_provider_override_allows_http_loopback(): void
    {
        $factory = $this->makeFactory(provider: 'custom', baseUrl: 'http://localhost:11434/v1');
        $method = (new \ReflectionClass($factory))->getMethod('buildCustomProviderOverride');
        $override = $method->invoke($factory);

        self::assertInstanceOf(OpenAIProvider::class, $override);
        self::assertSame('custom', $override->name());
        self::assertSame(
            'http://localhost:11434/v1',
            $override->endpoint(),
            'a loopback host is the one http exception the SSRF guard allows',
        );
    }

    public function test_custom_provider_override_returns_null_for_ftp_scheme(): void
    {
        $factory = $this->makeFactory(provider: 'custom', baseUrl: 'ftp://evil.example.com');
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('buildCustomProviderOverride');
        $override = $method->invoke($factory);

        self::assertNull($override);
    }

    public function test_custom_provider_override_returns_null_for_javascript_scheme(): void
    {
        $factory = $this->makeFactory(provider: 'custom', baseUrl: 'javascript:alert(1)');
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('buildCustomProviderOverride');
        $override = $method->invoke($factory);

        self::assertNull($override);
    }

    public function test_custom_provider_override_returns_null_for_empty_url(): void
    {
        $factory = $this->makeFactory(provider: 'custom', baseUrl: '');
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('buildCustomProviderOverride');
        $override = $method->invoke($factory);

        self::assertNull($override);
    }

    public function test_non_custom_provider_skips_override(): void
    {
        $factory = $this->makeFactory(provider: 'anthropic', baseUrl: 'https://ignored.example.com');
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('buildCustomProviderOverride');
        $override = $method->invoke($factory);

        self::assertNull($override);
    }

    public function test_shell_tool_wired_with_allowlist(): void
    {
        $tools = $this->makeFactory(
            tools: [ShellTool::class],
            shellAllowlist: ['ls', 'whoami'],
        )->create()->config()->tools;

        $shell = null;
        foreach ($tools as $tool) {
            if ($tool instanceof ShellTool) {
                $shell = $tool;
            }
        }

        self::assertNotNull($shell, 'ShellTool must reach the engine tool set');

        $allowlist = new \ReflectionProperty(ShellTool::class, 'allowlist');
        $allowlist->setAccessible(true);

        self::assertSame(['ls', 'whoami'], $allowlist->getValue($shell));
    }

    public function test_file_read_tool_wired_with_workspace_root(): void
    {
        $tools = $this->makeFactory(tools: [FileReadTool::class])->create()->config()->tools;

        $fileRead = null;
        foreach ($tools as $tool) {
            if ($tool instanceof FileReadTool) {
                $fileRead = $tool;
            }
        }

        self::assertNotNull($fileRead, 'FileReadTool must reach the engine tool set');

        $root = new \ReflectionProperty(FileReadTool::class, 'workspaceRootConfigured');
        $root->setAccessible(true);

        self::assertSame(realpath(sys_get_temp_dir()), realpath($root->getValue($fileRead)));
    }

    public function test_file_write_tool_wired_with_workspace_root(): void
    {
        $tools = $this->makeFactory(tools: [FileWriteTool::class])->create()->config()->tools;

        $fileWrite = null;
        foreach ($tools as $tool) {
            if ($tool instanceof FileWriteTool) {
                $fileWrite = $tool;
            }
        }

        self::assertNotNull($fileWrite, 'FileWriteTool must reach the engine tool set');

        $root = new \ReflectionProperty(FileWriteTool::class, 'workspaceRoot');
        $root->setAccessible(true);

        self::assertSame(realpath(sys_get_temp_dir()), realpath($root->getValue($fileWrite)));
    }

    public function test_tool_deny_removes_named_tool(): void
    {
        $factory = $this->makeFactory(
            tools: [ShellTool::class],
            toolDeny: ['shell_exec'],
        );
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        self::assertNotContains(
            'shell_exec',
            array_map(static fn (object $t): string => $t->name(), $tools),
        );
    }

    public function test_create_tool_registry_also_applies_tool_deny(): void
    {
        $factory = $this->makeFactory(
            tools: [ShellTool::class],
            toolDeny: ['shell_exec'],
        );

        $registry = $factory->createToolRegistry();

        self::assertFalse($registry->has('shell_exec'), 'createToolRegistry() must apply tool_deny for the MCP path.');
    }

    public function test_tool_deny_group_system_removes_shell_and_database_tools(): void
    {
        $factory = $this->makeFactory(
            tools: [ShellTool::class, FileReadTool::class],
            toolDeny: ['group:system'],
        );
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        $names = array_map(static fn (ToolInterface $t): string => $t->name(), $tools);

        self::assertNotContains('shell_exec', $names);
        self::assertNotContains('file_read', $names);
    }

    public function test_create_tool_registry_applies_group_deny_without_double_warning(): void
    {
        $factory = $this->makeFactory(
            tools: [ShellTool::class],
            toolDeny: ['group:system'],
        );

        $registry = $factory->createToolRegistry();

        self::assertFalse($registry->has('shell_exec'));
    }

    public function test_duplicate_tool_class_added_only_once(): void
    {
        $factory = $this->makeFactory(tools: [ShellTool::class, ShellTool::class]);
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        $shellCount = 0;
        foreach ($tools as $tool) {
            if ($tool instanceof ShellTool) {
                $shellCount++;
            }
        }

        self::assertSame(1, $shellCount);
    }

    public function test_unknown_tool_class_is_skipped(): void
    {
        $factory = $this->makeFactory(tools: ['App\\Tool\\DoesNotExist']);
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        self::assertContainsOnlyInstancesOf(ToolInterface::class, $tools);
    }

    public function test_non_string_tool_entry_is_skipped(): void
    {
        $factory = $this->makeFactory(tools: [123, null, true]);
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        self::assertContainsOnlyInstancesOf(ToolInterface::class, $tools);
    }

    public function test_resolve_tools_auto_loads_core_defaults(): void
    {
        $factory = $this->makeFactory(tools: []);
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $names = array_map(static fn ($t) => $t->name(), $method->invoke($factory, false));

        self::assertContains('shell_exec', $names);
        self::assertContains('file_read', $names);
        self::assertContains('http_request', $names);
    }

    public function test_store_messages_false_wraps_in_privacy_aware_memory(): void
    {
        $memory = $this->makeFactory(storeMessages: false)->create()->memory();

        self::assertInstanceOf(PrivacyAwareMemory::class, $memory);
        self::assertFalse($memory->storeMessages());
    }

    public function test_store_messages_true_wraps_in_privacy_aware_memory(): void
    {
        $memory = $this->makeFactory(storeMessages: true)->create()->memory();

        self::assertInstanceOf(PrivacyAwareMemory::class, $memory);
        self::assertTrue($memory->storeMessages());
    }

    public function test_resolve_skills_with_no_config_returns_no_inline_skills(): void
    {
        $factory = $this->makeFactory(skills: []);
        $method = (new \ReflectionClass($factory))->getMethod('resolveSkills');

        self::assertSame([], $method->invoke($factory));
    }

    public function test_extension_tools_merged_into_resolved_tools(): void
    {
        $extensions = new PhpClawExtensions;
        $extensions->tools[] = new LogTool(sys_get_temp_dir());

        $factory = $this->makeFactory(tools: [], extensions: $extensions);
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        $names = array_map(fn ($t) => $t->name(), $tools);
        self::assertContains('read_log', $names);
    }

    public function test_extension_tool_deduplicated_when_also_in_tool_list(): void
    {
        $logTool = new LogTool(sys_get_temp_dir());
        $extensions = new PhpClawExtensions;
        $extensions->tools[] = $logTool;

        $factory = $this->makeFactory(tools: [LogTool::class], extensions: $extensions);
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        $logCount = count(array_filter($tools, fn ($t) => $t instanceof LogTool));
        self::assertSame(1, $logCount);
    }

    private function makeFactory(
        string $provider = 'openai',
        string $baseUrl = '',
        array $tools = [],
        array $shellAllowlist = ['ls', 'php'],
        array $toolDeny = [],
        bool $storeMessages = false,
        array $skills = [],
        ?PhpClawExtensions $extensions = null,
        string $projectRoot = '',
    ): PhpClawFactory {
        return new PhpClawFactory(
            apiKey: 'sk-test',
            provider: $provider,
            model: '',
            storeMessages: $storeMessages,
            maxIterations: 10,
            shellAllowlist: $shellAllowlist,
            tools: $tools,
            memory: new ArrayMemory,
            workspaceRoot: sys_get_temp_dir(),
            systemPrompt: '',
            maxTokens: 0,
            promptCache: false,
            thinkingBudget: 0,
            baseUrl: $baseUrl,
            toolDeny: $toolDeny,
            skills: $skills,
            extensions: $extensions ?? new PhpClawExtensions,
            projectRoot: $projectRoot,
        );
    }

    public function test_log_tool_receives_the_project_root_log_directory_when_configured(): void
    {
        $factory = $this->makeFactory(tools: [LogTool::class], projectRoot: '/var/www/app');
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        $logTool = current(array_filter($tools, fn ($t) => $t instanceof LogTool));
        self::assertNotFalse($logTool);

        $logDirProp = new \ReflectionProperty(LogTool::class, 'logDir');
        self::assertSame('/var/www/app/var/log', $logDirProp->getValue($logTool));
    }

    public function test_log_tool_log_dir_is_empty_when_project_root_not_configured(): void
    {
        $factory = $this->makeFactory(tools: [LogTool::class], projectRoot: '');
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        $logTool = current(array_filter($tools, fn ($t) => $t instanceof LogTool));
        self::assertNotFalse($logTool);

        $logDirProp = new \ReflectionProperty(LogTool::class, 'logDir');
        self::assertSame('', $logDirProp->getValue($logTool));
    }

    public function test_project_info_tool_uses_the_configured_project_root(): void
    {
        $root = rtrim(sys_get_temp_dir(), '/');
        $factory = $this->makeFactory(tools: [], projectRoot: $root);
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        $projectTool = current(array_filter($tools, fn ($t) => $t->name() === 'project_info'));
        self::assertNotFalse($projectTool);

        $result = json_decode((string) $projectTool->execute([]), true);
        self::assertSame($root, $result['data']['root'], 'ToolCatalogue must pass the configured projectRoot into ProjectTool.');
    }

    public function test_project_info_tool_falls_back_to_cwd_when_project_root_not_configured(): void
    {
        $factory = $this->makeFactory(tools: [], projectRoot: '');
        $ref = new \ReflectionClass($factory);
        $method = $ref->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        $projectTool = current(array_filter($tools, fn ($t) => $t->name() === 'project_info'));
        self::assertNotFalse($projectTool);

        $result = json_decode((string) $projectTool->execute([]), true);
        self::assertSame((string) getcwd(), $result['data']['root'], 'With no projectRoot configured, ProjectTool keeps its own getcwd() default.');
    }
}
