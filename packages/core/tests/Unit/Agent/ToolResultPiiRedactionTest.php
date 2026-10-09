<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Claw;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tests\Unit\Agent\Durable\ScriptedProvider;
use PhpClaw\Tools\Contracts\ToolInterface;
use PHPUnit\Framework\TestCase;

final class ToolResultPiiRedactionTest extends TestCase
{
    private \ArrayObject $seen;

    protected function setUp(): void
    {
        HookRegistry::reset();
        $this->seen = new \ArrayObject;
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    private function recordingProvider(): ProviderInterface
    {
        $inner = new ScriptedProvider([ScriptedProvider::batch(ScriptedProvider::call('c1', 'customer_lookup'))]);

        return new class($inner, $this->seen) implements ProviderInterface
        {
            public function __construct(private readonly ScriptedProvider $inner, private readonly \ArrayObject $seen) {}

            public function send(array $messages, array $tools = []): array
            {
                $this->seen[] = json_encode(array_map(static fn ($m): array => $m->toArray(), $messages));

                return $this->inner->send($messages, $tools);
            }

            public function stream(array $messages, callable $onToken): string
            {
                return '';
            }

            public function name(): string
            {
                return 'scripted';
            }

            public function model(): string
            {
                return 'scripted-1';
            }
        };
    }

    private function customerTool(): ToolInterface
    {
        return new class implements ToolInterface
        {
            public function name(): string
            {
                return 'customer_lookup';
            }

            public function description(): string
            {
                return 'find a customer';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                return '{"success":true,"data":{"name":"Jane","email":"jane@shop.test"},"meta":{},"warnings":[]}';
            }
        };
    }

    private function askThroughClaw(callable $configure): string
    {
        $builder = Claw::builder()->providerOverride($this->recordingProvider())->memory(new ArrayMemory)->useDefaultGuards(false)->tools([$this->customerTool()]);
        $configure($builder);
        $builder->build()->send('who is the customer?');

        $requests = $this->seen->getArrayCopy();

        return (string) end($requests);
    }

    public function test_an_email_in_a_tool_result_is_redacted_before_the_next_model_call(): void
    {
        $nextRequest = $this->askThroughClaw(static fn ($b) => $b->redactPiiInToolResults());

        $this->assertCount(2, $this->seen);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $nextRequest);
        $this->assertStringNotContainsString('jane@shop.test', $nextRequest);
    }

    public function test_without_the_switch_the_model_sees_the_real_value(): void
    {
        $nextRequest = $this->askThroughClaw(static fn ($b) => $b);

        $this->assertStringContainsString('jane@shop.test', $nextRequest);
    }

    public function test_an_exempt_tool_reaches_the_model_unredacted(): void
    {
        $nextRequest = $this->askThroughClaw(static fn ($b) => $b->redactPiiInToolResults(exemptTools: ['customer_lookup']));

        $this->assertStringContainsString('jane@shop.test', $nextRequest);
    }
}
