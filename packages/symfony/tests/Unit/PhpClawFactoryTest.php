<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit;

use PhpClaw\Claw as PhpClaw;
use PhpClaw\Contracts\ClawInterface as PhpClawInterface;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\PhpClawFactory;
use PHPUnit\Framework\TestCase;

final class PhpClawFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('OPENAI_API_KEY=sk-test-factory');
    }

    protected function tearDown(): void
    {
        putenv('OPENAI_API_KEY');
    }

    public function test_create_returns_phpclaw_instance(): void
    {
        $factory = $this->makeFactory();
        $engine = $factory->create();

        self::assertInstanceOf(PhpClaw::class, $engine);
        self::assertInstanceOf(PhpClawInterface::class, $engine);
    }

    public function test_system_prompt_flows_through_to_provider(): void
    {
        $factory = $this->makeFactory(systemPrompt: 'You are a senior Symfony engineer.');
        $engine = $factory->create();

        $systemPrompt = $this->extractProviderSystemPrompt($engine);
        self::assertNotNull($systemPrompt);
        self::assertStringContainsString('Symfony engineer', $systemPrompt);
    }

    public function test_unknown_tool_classes_are_skipped(): void
    {
        $factory = $this->makeFactory(tools: ['Unknown\\NotAClass']);

        $method = (new \ReflectionClass($factory))->getMethod('resolveTools');
        $tools = $method->invoke($factory, false);

        self::assertNotContains(
            'Unknown\\NotAClass',
            array_map(static fn (object $t): string => $t::class, $tools),
            'a class that does not exist must never reach the tool list',
        );
    }

    private function makeFactory(
        string $systemPrompt = '',
        int $maxTokens = 0,
        bool $promptCache = false,
        int $thinkingBudget = 0,
        array $tools = [],
    ): PhpClawFactory {
        return new PhpClawFactory(
            apiKey: '',
            provider: 'openai',
            model: '',
            storeMessages: false,
            maxIterations: 10,
            shellAllowlist: ['ls', 'php'],
            tools: $tools,
            memory: new ArrayMemory,
            workspaceRoot: sys_get_temp_dir(),
            systemPrompt: $systemPrompt,
            maxTokens: $maxTokens,
            promptCache: $promptCache,
            thinkingBudget: $thinkingBudget,
        );
    }

    private function extractProviderSystemPrompt(PhpClaw $engine): ?string
    {
        $engineRef = new \ReflectionClass($engine);
        if (! $engineRef->hasProperty('agent')) {
            return null;
        }
        $agent = $engineRef->getProperty('agent')->getValue($engine);

        $agentRef = new \ReflectionClass($agent);
        if (! $agentRef->hasProperty('provider')) {
            return null;
        }
        $provider = $agentRef->getProperty('provider')->getValue($agent);

        $providerRef = new \ReflectionClass($provider);
        if (! $providerRef->hasProperty('systemPrompt')) {
            return null;
        }

        return (string) $providerRef->getProperty('systemPrompt')->getValue($provider);
    }
}
