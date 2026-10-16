<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\AgentDefinition;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;

final class AgentDefinitionTest extends TestCase
{
    public function test_an_agent_with_only_instructions_uses_the_defaults(): void
    {
        $agent = new class extends AgentDefinition
        {
            public function instructions(): string
            {
                return 'Help candidates find jobs.';
            }
        };

        $this->assertSame('Help candidates find jobs.', $agent->instructions());
        $this->assertSame([], $agent->tools());
        $this->assertSame('', $agent->provider());
        $this->assertSame('', $agent->model());
    }

    public function test_a_subclass_overrides_tools_provider_and_model(): void
    {
        $tool = $this->createMock(ToolInterface::class);
        $agent = new class($tool) extends AgentDefinition
        {
            public function __construct(private readonly ToolInterface $tool) {}

            public function instructions(): string
            {
                return 'Sell.';
            }

            public function tools(): array
            {
                return [$this->tool];
            }

            public function provider(): string
            {
                return 'openai';
            }

            public function model(): string
            {
                return 'gpt-4o-mini';
            }
        };

        $this->assertSame([$tool], $agent->tools());
        $this->assertSame('openai', $agent->provider());
        $this->assertSame('gpt-4o-mini', $agent->model());
    }
}
