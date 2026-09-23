<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit;

use Illuminate\Contracts\Foundation\Application;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Engine\EngineFactory;
use PhpClaw\Laravel\LaravelConsole;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class LaravelConsoleTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = ['artisan'];

        parent::tearDown();
    }

    public function test_a_web_request_is_never_interactive_console(): void
    {
        $app = $this->app;
        $app['config']->set('app.running_in_console', false);

        $this->assertFalse(LaravelConsole::isInteractive($this->webApplication()));
    }

    public function test_an_artisan_command_is_interactive_console(): void
    {
        $_SERVER['argv'] = ['artisan', 'phpclaw'];

        $this->assertTrue(LaravelConsole::isInteractive($this->app));
        $this->assertFalse(LaravelConsole::isQueueWorker($this->app));
    }

    public function test_a_queue_worker_is_not_interactive_console(): void
    {
        foreach (LaravelConsole::WORKER_COMMANDS as $command) {
            $_SERVER['argv'] = ['artisan', $command];

            $this->assertTrue(
                LaravelConsole::isQueueWorker($this->app),
                $command.' must be recognised as a queue worker',
            );
            $this->assertFalse(
                LaravelConsole::isInteractive($this->app),
                $command.' must not hold the console exemption',
            );
        }
    }

    public function test_php_write_is_off_on_a_queue_worker_and_on_for_artisan(): void
    {
        $_SERVER['argv'] = ['artisan', 'phpclaw'];
        $this->assertTrue($this->phpWriteAllowed());

        $_SERVER['argv'] = ['artisan', 'queue:work'];
        $this->assertFalse($this->phpWriteAllowed());
    }

    public function test_a_host_app_can_extend_the_worker_command_list(): void
    {
        $this->app['config']->set('phpclaw.worker_commands', 'app:consume-agent-jobs');
        $_SERVER['argv'] = ['artisan', 'app:consume-agent-jobs'];

        $this->assertTrue(LaravelConsole::isQueueWorker($this->app));
        $this->assertFalse(LaravelConsole::isInteractive($this->app));
    }

    public function test_a_host_app_worker_command_does_not_disable_the_others(): void
    {
        $this->app['config']->set('phpclaw.worker_commands', 'app:consume-agent-jobs');
        $_SERVER['argv'] = ['artisan', 'queue:work'];

        $this->assertTrue(LaravelConsole::isQueueWorker($this->app));
    }

    private function phpWriteAllowed(): bool
    {
        foreach (EngineFactory::resolveTools($this->app) as $tool) {
            if ($tool->name() === 'file_write') {
                $reflection = new \ReflectionProperty($tool, 'allowPhpWrite');

                return (bool) $reflection->getValue($tool);
            }
        }

        self::fail('file_write tool was not built.');
    }

    private function webApplication(): Application
    {
        $app = clone $this->app;

        $reflection = new \ReflectionProperty($app, 'isRunningInConsole');
        $reflection->setValue($app, false);

        return $app;
    }
}
