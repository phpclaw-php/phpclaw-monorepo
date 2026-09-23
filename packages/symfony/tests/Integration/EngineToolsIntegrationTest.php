<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Integration;

use PhpClaw\Claw as PhpClaw;

final class EngineToolsIntegrationTest extends IntegrationTestCase
{
    public function test_engine_has_core_default_tools(): void
    {
        IntegrationKernel::configure([]);

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        /** @var PhpClaw $phpClaw */
        $phpClaw = $container->get(PhpClaw::class);
        $toolNames = array_map(fn ($t) => $t->name(), $phpClaw->config()->tools);

        $this->assertNotEmpty($toolNames, 'Engine must have at least one tool registered.');
        $this->assertContains('http_request', $toolNames, 'http_request is a core default tool.');
        $this->assertContains('shell_exec', $toolNames, 'shell_exec is a core default tool.');
        $this->assertContains('file_read', $toolNames, 'file_read is a core default tool.');
        $this->assertContains('file_write', $toolNames, 'file_write is a core default tool.');
    }

    public function test_tool_deny_removes_denied_tool_from_engine(): void
    {
        IntegrationKernel::configure([
            'tool_deny' => ['http_request'],
        ]);

        $kernel = self::bootKernel();
        $container = $kernel->getContainer();

        /** @var PhpClaw $phpClaw */
        $phpClaw = $container->get(PhpClaw::class);
        $toolNames = array_map(fn ($t) => $t->name(), $phpClaw->config()->tools);

        $this->assertNotContains('http_request', $toolNames, 'Denied tool must not appear in the engine tool list.');
    }
}
