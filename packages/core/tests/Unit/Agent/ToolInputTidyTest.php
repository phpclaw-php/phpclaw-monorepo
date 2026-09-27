<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Agent;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ToolInputTidyTest extends TestCase
{
    private array $received = [];

    private function runWith(array $input): array
    {
        $this->received = [];
        $test = $this;
        $tool = new class($test) implements ToolInterface
        {
            public function __construct(private ToolInputTidyTest $test) {}

            public function name(): string
            {
                return 'probe';
            }

            public function description(): string
            {
                return 'probe';
            }

            public function inputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => [
                        'search' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'enum' => ['all', '*']],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50],
                        'ratio' => ['type' => 'number', 'minimum' => 0.5],
                        'code' => ['type' => 'string'],
                    ],
                    'required' => ['code'],
                ];
            }

            public function execute(array $input): string
            {
                $this->test->record($input);

                return 'ok';
            }
        };

        $provider = $this->createMock(ProviderInterface::class);
        $provider->method('name')->willReturn('anthropic');
        $provider->method('model')->willReturn('claude-haiku-4-5-20251001');
        $call = 0;
        $provider->method('send')->willReturnCallback(function () use (&$call, $input): array {
            return $call++ === 0
                ? ['type' => 'tool_use_batch', 'calls' => [['tool_use_id' => 't1', 'tool_name' => 'probe', 'tool_input' => $input]]]
                : ['type' => 'text', 'text' => 'done'];
        });

        $registry = new ToolRegistry;
        $registry->register([$tool]);
        (new Agent($provider, $registry))->run('go');

        return $this->received;
    }

    public function record(array $input): void
    {
        $this->received = $input;
    }

    public function test_null_optional_argument_is_dropped(): void
    {
        $this->assertSame(['code' => 'x'], $this->runWith(['search' => null, 'code' => 'x']));
    }

    public function test_blank_optional_argument_is_dropped(): void
    {
        $this->assertSame(['code' => 'x'], $this->runWith(['search' => '  ', 'code' => 'x']));
    }

    public function test_wildcard_optional_argument_is_dropped(): void
    {
        $this->assertSame(['code' => 'x'], $this->runWith(['search' => '*', 'code' => 'x']));
        $this->assertSame(['code' => 'x'], $this->runWith(['search' => '%', 'code' => 'x']));
    }

    public function test_wildcard_is_kept_when_it_is_an_enum_value(): void
    {
        $this->assertSame(['status' => '*', 'code' => 'x'], $this->runWith(['status' => '*', 'code' => 'x']));
    }

    public function test_required_argument_is_kept_even_when_blank(): void
    {
        $this->assertSame(['code' => ''], $this->runWith(['code' => '']));
    }

    public function test_numbers_are_capped_to_the_schema_range(): void
    {
        $this->assertSame(['limit' => 50, 'code' => 'x'], $this->runWith(['limit' => 100, 'code' => 'x']));
        $this->assertSame(['limit' => 1, 'code' => 'x'], $this->runWith(['limit' => 0, 'code' => 'x']));
        $this->assertSame(['ratio' => 0.5, 'code' => 'x'], $this->runWith(['ratio' => 0.1, 'code' => 'x']));
    }

    public function test_in_range_and_non_numeric_values_are_untouched(): void
    {
        $this->assertSame(['limit' => 20, 'search' => 'abc', 'code' => 'x'], $this->runWith(['limit' => 20, 'search' => 'abc', 'code' => 'x']));
        $this->assertSame(['limit' => 'many', 'code' => 'x'], $this->runWith(['limit' => 'many', 'code' => 'x']));
    }

    public function test_arguments_not_in_the_schema_are_untouched(): void
    {
        $this->assertSame(['extra' => '', 'code' => 'x'], $this->runWith(['extra' => '', 'code' => 'x']));
    }
}
