<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Console;

use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\SkillRegistry;

final class McpServerCommandTest extends TestCase
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
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    public function test_command_is_registered(): void
    {
        $commands = $this->app->make(Kernel::class)->all();

        $this->assertArrayHasKey('phpclaw:mcp-server', $commands);
    }

    public function test_command_description_is_set(): void
    {
        $commands = $this->app->make(Kernel::class)->all();

        $this->assertSame(
            'Start the phpClaw MCP server (stdio or HTTP transport)',
            $commands['phpclaw:mcp-server']->getDescription(),
        );
    }

    public function test_http_transport_without_token_returns_failure(): void
    {
        putenv('PHPCLAW_MCP_TOKEN=');

        $this->artisan('phpclaw:mcp-server', ['--transport' => 'http'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Refusing to start');

        putenv('PHPCLAW_MCP_TOKEN');
    }

    public function test_http_transport_without_token_emits_error_output(): void
    {
        putenv('PHPCLAW_MCP_TOKEN=');

        $result = $this->artisan('phpclaw:mcp-server', ['--transport' => 'http']);
        $result->assertFailed();

        putenv('PHPCLAW_MCP_TOKEN');
    }
}
