<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Mockery;
use Orchestra\Testbench\TestCase;
use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\SkillRegistry;
use PhpClaw\Tools\FileWriteTool;

final class PhpWriteApprovalGateTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function setUp(): void
    {
        SkillRegistry::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        SkillRegistry::reset();
        Mockery::close();
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    public function test_cli_context_enables_php_write_and_wires_approval_gate(): void
    {
        $engine = EngineFactory::build($this->app);

        $this->assertInstanceOf(CliApprovalGate::class, $this->approvalGate($engine));
        $this->assertTrue($this->fileWriteAllowsPhp($engine));
    }

    public function test_http_context_disables_php_write_but_still_wires_approval_gate(): void
    {
        $httpApp = Mockery::mock($this->app)->makePartial();
        $httpApp->shouldReceive('runningInConsole')->andReturn(false);

        $engine = EngineFactory::build($httpApp);

        $this->assertInstanceOf(CliApprovalGate::class, $this->approvalGate($engine));
        $this->assertFalse($this->fileWriteAllowsPhp($engine));
    }

    public function test_mcp_tool_registry_forces_php_write_off_even_under_console(): void
    {
        $this->assertTrue(
            $this->app->runningInConsole(),
            'This test only proves the fix if the test app itself reports console=true, the exact condition that used to leak allowPhpWrite=true into MCP.',
        );

        $tools = EngineFactory::resolveTools($this->app, forceAllowPhpWrite: false);

        $found = false;
        foreach ($tools as $tool) {
            if ($tool instanceof FileWriteTool) {
                $found = true;
                $this->assertFalse((bool) $this->readProperty($tool, 'allowPhpWrite'), 'MCP tool list must never allow PHP writes.');
            }
        }
        $this->assertTrue($found, 'FileWriteTool not present in the resolved tool list.');
    }

    private function approvalGate(ClawInterface $engine): ?object
    {
        $config = $this->readProperty($engine, 'config');

        return $this->readProperty($config, 'approvalGate');
    }

    private function fileWriteAllowsPhp(ClawInterface $engine): bool
    {
        $config = $this->readProperty($engine, 'config');

        foreach ((array) $this->readProperty($config, 'tools') as $tool) {
            if ($tool instanceof FileWriteTool) {
                return (bool) $this->readProperty($tool, 'allowPhpWrite');
            }
        }

        $this->fail('FileWriteTool not present in the built engine.');
    }

    private function readProperty(object $object, string $name): mixed
    {
        return (new \ReflectionProperty($object, $name))->getValue($object);
    }
}
