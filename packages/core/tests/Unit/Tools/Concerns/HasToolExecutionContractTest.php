<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Tools\Concerns;

use PhpClaw\Tools\Concerns\HasToolExecutionContract;
use PHPUnit\Framework\TestCase;

final class HasToolExecutionContractTest extends TestCase
{
    private function makeTool(array $performResult): object
    {
        return new class($performResult)
        {
            use HasToolExecutionContract;

            public function __construct(private readonly array $performResult) {}

            public function requiredCapability(): string
            {
                return 'test.capability';
            }

            protected function runningInConsole(): bool
            {
                return true;
            }

            protected function callerHasCapability(string $capability): bool
            {
                return true;
            }

            protected function plan(array $input): array
            {
                return ['input' => $input, 'result' => null];
            }

            protected function perform(array $input): array
            {
                return $this->performResult;
            }

            protected function verify(array $execution, array $input): array
            {
                return ['result' => null];
            }

            protected function complete(array $execution, array $input): string
            {
                return $this->success($execution, ['mode' => 'query']);
            }
        };
    }

    public function test_success_decodes_html_entities_in_string_fields(): void
    {
        $tool = $this->makeTool([
            'name' => 'Apple Cinema 30&quot;',
            'category' => 'Laptops &amp; Notebooks',
        ]);

        $result = json_decode($tool->execute([]), true);

        self::assertSame('Apple Cinema 30"', $result['data']['name']);
        self::assertSame('Laptops & Notebooks', $result['data']['category']);
    }

    public function test_success_decodes_html_entities_in_nested_rows(): void
    {
        $tool = $this->makeTool([
            'rows' => [
                ['name' => 'Tom &amp; Jerry'],
                ['name' => 'Salt &amp; Pepper'],
            ],
        ]);

        $result = json_decode($tool->execute([]), true);

        self::assertSame('Tom & Jerry', $result['data']['rows'][0]['name']);
        self::assertSame('Salt & Pepper', $result['data']['rows'][1]['name']);
    }

    public function test_success_leaves_plain_text_and_non_string_values_unchanged(): void
    {
        $tool = $this->makeTool([
            'name' => 'Plain product name',
            'quantity' => 42,
            'enabled' => true,
            'discount' => null,
        ]);

        $result = json_decode($tool->execute([]), true);

        self::assertSame('Plain product name', $result['data']['name']);
        self::assertSame(42, $result['data']['quantity']);
        self::assertTrue($result['data']['enabled']);
        self::assertNull($result['data']['discount']);
    }

    public function test_success_does_not_mangle_a_literal_ampersand_that_is_not_an_entity(): void
    {
        $tool = $this->makeTool(['name' => 'Smith & Sons']);

        $result = json_decode($tool->execute([]), true);

        self::assertSame('Smith & Sons', $result['data']['name']);
    }
}
