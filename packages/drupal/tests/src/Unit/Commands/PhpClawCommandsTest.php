<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Commands;

use Drush\Commands\DrushCommands;
use PhpClaw\Agent\AgentResponse;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Drupal\Commands\PhpClawCommands;
use PHPUnit\Framework\TestCase;

final class PhpClawCommandsTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(DrushCommands::class)) {
            $this->markTestSkipped('Drush runtime not available in this composer install.');
        }
    }

    public function test_constructor_takes_only_the_agent(): void
    {
        $ref = new \ReflectionMethod(PhpClawCommands::class, '__construct');

        self::assertCount(1, $ref->getParameters());
        self::assertSame('agent', $ref->getParameters()[0]->getName());
    }

    public function test_run_is_the_only_public_command_method(): void
    {
        $ref = new \ReflectionClass(PhpClawCommands::class);

        $own = array_values(array_filter(
            array_map(
                static fn (\ReflectionMethod $m): string => $m->getName(),
                $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            ),
            static fn (string $n): bool => ! str_starts_with($n, '__'),
        ));

        self::assertSame(['run'], $own);
    }

    public function test_run_returns_failure_when_no_agent_is_configured(): void
    {
        $command = new PhpClawCommands(null);

        self::assertSame(1, $command->run('hello'));
    }

    public function test_run_prints_the_agent_response(): void
    {
        $agent = $this->createMock(ClawInterface::class);
        $agent->method('send')->willReturn(
            new AgentResponse(text: 'answered', provider: 'ollama', model: 'test', iterations: 1),
        );

        $command = new PhpClawCommands($agent);

        ob_start();
        $code = $command->run('hello');
        $output = (string) ob_get_clean();

        self::assertSame(0, $code);
        self::assertStringContainsString('answered', $output);
    }
}
