<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\ProjectTool;

final class EngineFactoryProjectRootTest extends TestCase
{
    private string $startDirectory = '';

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
        $this->startDirectory = (string) getcwd();
    }

    protected function tearDown(): void
    {
        chdir($this->startDirectory);
        parent::tearDown();
        SkillRegistry::reset();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    private function projectInfo(): array
    {
        foreach (EngineFactory::resolveTools($this->app) as $tool) {
            if ($tool instanceof ProjectTool) {
                return json_decode($tool->execute([]), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        $this->fail('project_info was not registered.');
    }

    public function test_project_info_describes_the_laravel_app_when_the_process_starts_at_the_filesystem_root(): void
    {
        chdir('/');

        $result = $this->projectInfo();

        $this->assertTrue($result['success']);
        $this->assertSame(base_path(), $result['data']['root']);
    }

    public function test_project_info_describes_the_laravel_app_from_any_working_directory(): void
    {
        chdir(sys_get_temp_dir());

        $this->assertSame(base_path(), $this->projectInfo()['data']['root']);
    }
}
