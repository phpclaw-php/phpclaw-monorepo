<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Integration;

use PhpClaw\Claw as PhpClaw;
use PhpClaw\Contracts\ClawInterface;
use PhpClaw\Memory\Contracts\MemoryInterface;

final class ConfigReflectionIntegrationTest extends IntegrationTestCase
{
    public function test_max_iterations_reaches_engine(): void
    {
        IntegrationKernel::configure(['max_iterations' => 7]);

        $kernel = self::bootKernel();
        $phpClaw = $kernel->getContainer()->get(PhpClaw::class);

        $this->assertSame(7, $phpClaw->config()->maxIterations);
    }

    public function test_store_messages_off_reaches_engine(): void
    {
        IntegrationKernel::configure(['store_messages' => false]);

        $kernel = self::bootKernel();
        $phpClaw = $kernel->getContainer()->get(PhpClaw::class);

        $this->assertFalse($phpClaw->storeMessages(), 'store_messages=false must reach the engine.');
    }

    public function test_engine_memory_driver_is_file_memory_when_configured(): void
    {
        IntegrationKernel::configure(['store_messages' => false, 'memory_driver' => 'file']);

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        $memory = $container->get(MemoryInterface::class);

        $this->assertInstanceOf(MemoryInterface::class, $memory);
    }

    public function test_system_prompt_reaches_engine(): void
    {
        IntegrationKernel::configure(['system_prompt' => 'You are a test agent.', 'max_iterations' => 3]);

        $kernel = self::bootKernel();
        $phpClaw = $kernel->getContainer()->get(PhpClaw::class);

        $this->assertStringContainsString('You are a test agent.', $phpClaw->config()->systemPrompt);
    }

    public function test_engine_resolves_as_phpclaw_interface(): void
    {
        IntegrationKernel::configure([]);

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        $this->assertTrue($container->has(PhpClaw::class));
        $phpClaw = $container->get(PhpClaw::class);
        $this->assertInstanceOf(ClawInterface::class, $phpClaw);
    }

    public function test_max_tokens_reaches_engine(): void
    {
        IntegrationKernel::configure(['max_tokens' => 512]);

        $kernel = self::bootKernel();
        $phpClaw = $kernel->getContainer()->get(PhpClaw::class);

        $this->assertSame(512, $phpClaw->config()->maxTokens);
    }
}
