<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Tools;

use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\Tools\AbstractLaravelTool;
use PHPUnit\Framework\TestCase;

final class AbstractLaravelToolTest extends TestCase
{
    private object $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = new class extends AbstractLaravelTool
        {
            public function name(): string
            {
                return 'demo_tool';
            }

            public function description(): string
            {
                return 'Demo tool.';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function requiredCapability(): string
            {
                return LaravelIdentityResolver::CHAT_ABILITY;
            }

            protected function plan(array $input): array
            {
                return ['input' => $input, 'result' => null];
            }

            protected function perform(array $input): array
            {
                return ['echo' => $input];
            }

            protected function verify(array $execution, array $input): array
            {
                return ['result' => null];
            }

            protected function complete(array $execution, array $input): string
            {
                return $this->success(
                    ['echo' => $execution['echo']],
                    ['mode' => 'query', 'count' => 1, 'total' => 1, 'truncated' => false],
                );
            }

            public function callStringInput(array $input, string $key, string $default = ''): string
            {
                return $this->stringInput($input, $key, $default);
            }

            public function callTruncate(string $text): string
            {
                return $this->truncate($text);
            }

            public function callEncodeTruncated(mixed $data): ?string
            {
                return $this->encodeTruncated($data);
            }
        };
    }

    public function test_the_contract_returns_the_shared_envelope_shape(): void
    {
        $decoded = json_decode($this->tool->execute(['q' => 'hi']), true);

        self::assertIsArray($decoded);
        self::assertTrue($decoded['success']);
        self::assertSame(['echo' => ['q' => 'hi']], $decoded['data']);
        self::assertSame(
            ['mode', 'count', 'total', 'truncated'],
            array_keys($decoded['meta']),
        );
        self::assertSame([], $decoded['warnings']);
    }

    public function test_string_input_trims_a_present_string(): void
    {
        self::assertSame('hello', $this->tool->callStringInput(['q' => '  hello  '], 'q'));
    }

    public function test_string_input_falls_back_when_key_is_absent(): void
    {
        self::assertSame('fallback', $this->tool->callStringInput([], 'q', 'fallback'));
    }

    public function test_string_input_falls_back_when_value_is_not_a_string(): void
    {
        self::assertSame('fallback', $this->tool->callStringInput(['q' => 42], 'q', 'fallback'));
        self::assertSame('fallback', $this->tool->callStringInput(['q' => ['a']], 'q', 'fallback'));
        self::assertSame('fallback', $this->tool->callStringInput(['q' => null], 'q', 'fallback'));
    }

    public function test_string_input_default_is_the_empty_string(): void
    {
        self::assertSame('', $this->tool->callStringInput([], 'q'));
    }

    public function test_text_at_the_cap_is_returned_unchanged(): void
    {
        $text = str_repeat('a', 8192);

        self::assertSame($text, $this->tool->callTruncate($text));
    }

    public function test_text_over_the_cap_is_cut_and_marked(): void
    {
        $result = $this->tool->callTruncate(str_repeat('a', 8193));

        self::assertSame(
            str_repeat('a', 8192)."\n[... output truncated at 8 KB ...]",
            $result,
        );
    }

    public function test_encode_truncated_pretty_prints_and_leaves_unicode_unescaped(): void
    {
        self::assertSame(
            json_encode(['name' => 'café'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            $this->tool->callEncodeTruncated(['name' => 'café']),
        );
    }

    public function test_encode_truncated_returns_null_when_encoding_fails(): void
    {
        self::assertNull($this->tool->callEncodeTruncated(["\xB1\x31"]));
    }

    public function test_encode_truncated_applies_the_cap(): void
    {
        $json = $this->tool->callEncodeTruncated(['blob' => str_repeat('y', 9000)]);

        self::assertStringEndsWith("\n[... output truncated at 8 KB ...]", (string) $json);
    }
}
