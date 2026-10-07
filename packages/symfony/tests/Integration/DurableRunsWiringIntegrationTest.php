<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Integration;

use PhpClaw\Agent\CliApprovalGate;
use PhpClaw\Agent\SuspendableApprovalGate;
use PhpClaw\Claw;
use PhpClaw\Symfony\Command\PhpClawCommand;
use PhpClaw\Symfony\Http\RunsController;
use PhpClaw\Symfony\PhpClawFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Routing\Loader\YamlFileLoader;

final class DurableRunsWiringIntegrationTest extends IntegrationTestCase
{
    public function test_with_durable_runs_on_the_durable_engine_pauses_and_the_controller_is_on(): void
    {
        IntegrationKernel::configure(['durable_runs' => true, 'store_messages' => true]);
        $container = self::bootKernel()->getContainer();

        $engine = $container->get(PhpClawFactory::DURABLE_ENGINE);
        self::assertInstanceOf(Claw::class, $engine);
        self::assertInstanceOf(SuspendableApprovalGate::class, $engine->config()->approvalGate);
        self::assertTrue((new \ReflectionProperty(RunsController::class, 'durableRuns'))->getValue($container->get(RunsController::class)));
    }

    public function test_with_durable_runs_off_nothing_pauses_and_the_controller_is_off(): void
    {
        IntegrationKernel::configure([]);
        $container = self::bootKernel()->getContainer();

        self::assertInstanceOf(CliApprovalGate::class, $container->get(PhpClawFactory::DURABLE_ENGINE)->config()->approvalGate);
        self::assertNull($container->get(Claw::class)->config()->durableRuns);
        self::assertFalse((new \ReflectionProperty(RunsController::class, 'durableRuns'))->getValue($container->get(RunsController::class)));
    }

    public function test_the_runs_command_is_registered(): void
    {
        IntegrationKernel::configure(['durable_runs' => true, 'store_messages' => true]);
        $application = new Application(self::bootKernel());

        self::assertTrue($application->has('phpclaw:runs'));
    }

    public function test_with_durable_runs_on_the_chat_command_keeps_the_terminal_prompt(): void
    {
        IntegrationKernel::configure(['durable_runs' => true, 'store_messages' => true]);
        $command = (new Application(self::bootKernel()))->find('phpclaw');
        $command = $command instanceof LazyCommand ? $command->getCommand() : $command;

        self::assertInstanceOf(PhpClawCommand::class, $command);
        $engine = (new \ReflectionProperty(PhpClawCommand::class, 'phpClaw'))->getValue($command);
        self::assertInstanceOf(CliApprovalGate::class, $engine->config()->approvalGate);
        self::assertNotNull($engine->config()->durableRuns);
    }

    public function test_the_three_run_routes_are_shipped_with_the_run_id_pattern(): void
    {
        $routes = (new YamlFileLoader(new FileLocator(dirname(__DIR__, 2).'/config')))->load('routes.yaml');

        self::assertSame(['GET'], $routes->get('phpclaw_runs')?->getMethods());
        self::assertSame('/phpclaw/runs/{runId}/approve', $routes->get('phpclaw_runs_approve')?->getPath());
        self::assertSame('/phpclaw/runs/{runId}/deny', $routes->get('phpclaw_runs_deny')?->getPath());
        self::assertSame('[A-Za-z0-9]{26}', $routes->get('phpclaw_runs_approve')?->getRequirement('runId'));
    }
}
