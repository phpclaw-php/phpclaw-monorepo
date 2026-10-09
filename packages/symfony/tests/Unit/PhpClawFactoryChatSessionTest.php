<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Claw;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Symfony\Console\ConsoleContext;
use PhpClaw\Symfony\PhpClawFactory;
use PhpClaw\Symfony\Tools\TestRunTool;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileReadTool;
use PHPUnit\Framework\TestCase;

final class PhpClawFactoryChatSessionTest extends TestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        SkillRegistry::reset();
        $this->workspace = sys_get_temp_dir().'/phpclaw-sf-edit-'.uniqid();
        mkdir($this->workspace.'/src', 0777, true);
        file_put_contents($this->workspace.'/src/Kernel.php', "<?php\n// name: old\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->workspace.'/src/Kernel.php');
        @rmdir($this->workspace.'/src');
        @rmdir($this->workspace);
        SkillRegistry::reset();
    }

    private function factory(array $tools, string $environment = 'dev', string $projectRoot = ''): PhpClawFactory
    {
        $console = new ConsoleContext;
        $console->markConsole();

        return new PhpClawFactory(
            apiKey: 'test-key',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            storeMessages: false,
            maxIterations: 10,
            shellAllowlist: ['ls'],
            tools: $tools,
            memory: new ArrayMemory,
            workspaceRoot: $this->workspace,
            systemPrompt: '',
            maxTokens: 0,
            promptCache: false,
            thinkingBudget: 0,
            consoleContext: $console,
            projectRoot: $projectRoot,
            environment: $environment,
        );
    }

    private function tool(Claw $claw, string $class): ToolInterface
    {
        foreach ($claw->config()->tools as $tool) {
            if ($tool instanceof $class) {
                return $tool;
            }
        }

        $this->fail($class.' was not built from the tools config.');
    }

    public function test_file_edit_uses_the_configured_workspace_root(): void
    {
        $claw = $this->factory([FileReadTool::class, FileEditTool::class])->createForTerminal();

        $this->tool($claw, FileReadTool::class)->execute(['path' => 'src/Kernel.php']);
        $this->tool($claw, FileEditTool::class)->execute(['path' => 'src/Kernel.php', 'old_str' => 'name: old', 'new_str' => 'name: new']);

        $this->assertStringContainsString('name: new', (string) file_get_contents($this->workspace.'/src/Kernel.php'));
    }

    public function test_test_run_is_built_with_the_kernel_environment(): void
    {
        $claw = $this->factory([TestRunTool::class], environment: 'prod', projectRoot: $this->workspace)->createForTerminal();

        $result = json_decode($this->tool($claw, TestRunTool::class)->execute([]), true);

        $this->assertSame('PRODUCTION', $result['error']['code']);
    }

    public function test_test_run_is_built_with_the_project_root(): void
    {
        $claw = $this->factory([TestRunTool::class], projectRoot: $this->workspace)->createForTerminal();

        $result = json_decode($this->tool($claw, TestRunTool::class)->execute([]), true);

        $this->assertSame('NOT_INSTALLED', $result['error']['code']);
        $this->assertStringContainsString('vendor/bin/phpunit', $result['error']['message']);
    }

    public function test_create_for_terminal_applies_a_session_provider_and_model(): void
    {
        $factory = $this->factory([]);

        $default = $factory->createForTerminal();
        $session = $factory->createForTerminal(provider: 'openai', model: 'gpt-4o-mini');

        $this->assertSame('claude-haiku-4-5-20251001', $default->config()->model);
        $this->assertSame('openai', $session->config()->providerName);
        $this->assertSame('gpt-4o-mini', $session->config()->model);
    }

    public function test_create_for_terminal_with_only_a_model_keeps_the_configured_provider(): void
    {
        $session = $this->factory([])->createForTerminal(model: 'claude-sonnet-5-5');

        $this->assertSame('anthropic', $session->config()->providerName);
        $this->assertSame('claude-sonnet-5-5', $session->config()->model);
    }
}
