<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Console;

use Orchestra\Testbench\TestCase;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Skills\SkillRegistry;

final class AboutCommandTest extends TestCase
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
        $this->artisan('phpclaw:about')->assertSuccessful();
    }

    public function test_output_contains_banner(): void
    {
        $this->artisan('phpclaw:about')
            ->assertSuccessful()
            ->expectsOutputToContain('AI agents for Laravel');
    }

    public function test_output_contains_provider_line(): void
    {
        $this->artisan('phpclaw:about')
            ->assertSuccessful()
            ->expectsOutputToContain('Provider:');
    }

    public function test_output_contains_tools_line(): void
    {
        $this->artisan('phpclaw:about')
            ->assertSuccessful()
            ->expectsOutputToContain('Tools:');
    }

    public function test_output_contains_memory_line(): void
    {
        $this->artisan('phpclaw:about')
            ->assertSuccessful()
            ->expectsOutputToContain('Memory:');
    }

    public function test_output_contains_store_msgs_line(): void
    {
        $this->artisan('phpclaw:about')
            ->assertSuccessful()
            ->expectsOutputToContain('store_msgs:');
    }

    public function test_cloud_key_is_masked_when_set(): void
    {
        $this->app['config']->set('phpclaw.cloud_key', 'PG-ABCD1234');

        $this->artisan('phpclaw:about')
            ->assertSuccessful()
            ->expectsOutputToContain('****1234')
            ->doesntExpectOutputToContain('PG-ABCD1234');
    }

    public function test_cloud_key_shows_not_connected_when_empty(): void
    {
        $this->app['config']->set('phpclaw.cloud_key', '');

        $this->artisan('phpclaw:about')
            ->assertSuccessful()
            ->expectsOutputToContain('not connected');
    }

    public function test_test_option_sends_probe_and_reports_reachable(): void
    {
        $response = new AgentResponse(
            text: 'OK',
            provider: 'anthropic',
            model: 'claude-test',
            iterations: 1,
            inputTokens: 5,
            outputTokens: 2,
        );

        $mock = $this->createMock(PhpClawInterface::class);
        $mock->expects($this->once())
            ->method('send')
            ->willReturn($response);

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw:about', ['--test' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('reachable');
    }

    public function test_test_option_reports_failure_on_exception(): void
    {
        $mock = $this->createMock(PhpClawInterface::class);
        $mock->expects($this->once())
            ->method('send')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $this->app->instance(PhpClawInterface::class, $mock);

        $this->artisan('phpclaw:about', ['--test' => true])
            ->assertFailed()
            ->expectsOutputToContain('unreachable');
    }
}
