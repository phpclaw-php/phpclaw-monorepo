<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\FileEditTool;
use PhpClaw\Tools\FileReadTool;

final class EngineFactoryFileEditTest extends TestCase
{
    private string $workspace = '';

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/phpclaw-edit-'.uniqid();
        mkdir($this->workspace.'/app/Models', 0777, true);
        file_put_contents($this->workspace.'/app/Models/User.php', "<?php\n// name: old\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->workspace.'/app/Models/User.php');
        @rmdir($this->workspace.'/app/Models');
        @rmdir($this->workspace.'/app');
        @rmdir($this->workspace);
        parent::tearDown();
        SkillRegistry::reset();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
        $app['config']->set('phpclaw.tools', [FileReadTool::class, FileEditTool::class]);
    }

    private function tool(string $class): ToolInterface
    {
        foreach (EngineFactory::resolveTools($this->app) as $tool) {
            if ($tool instanceof $class) {
                return $tool;
            }
        }

        $this->fail($class.' was not resolved from the tools config.');
    }

    public function test_file_edit_uses_the_configured_workspace_root(): void
    {
        config(['phpclaw.workspace_root' => $this->workspace]);

        $this->tool(FileReadTool::class)->execute(['path' => 'app/Models/User.php']);
        $this->tool(FileEditTool::class)->execute(['path' => 'app/Models/User.php', 'old_str' => 'name: old', 'new_str' => 'name: new']);

        $this->assertStringContainsString('name: new', (string) file_get_contents($this->workspace.'/app/Models/User.php'));
    }

    public function test_file_edit_cannot_reach_a_file_outside_the_configured_workspace_root(): void
    {
        config(['phpclaw.workspace_root' => $this->workspace.'/app']);

        try {
            $this->tool(FileEditTool::class)->execute(['path' => '../app/Models/User.php', 'old_str' => 'name: old', 'new_str' => 'name: new']);
        } catch (\Throwable) {
        }

        $this->assertStringContainsString('name: old', (string) file_get_contents($this->workspace.'/app/Models/User.php'));
    }
}
