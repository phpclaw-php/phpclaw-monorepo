<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Tools;

use PhpClaw\Symfony\SymfonyIdentityResolver;
use PhpClaw\Symfony\Tests\Support\BuildsIdentity;
use PhpClaw\Symfony\Tools\AbstractSymfonyTool;
use PHPUnit\Framework\TestCase;

final class AbstractSymfonyToolTest extends TestCase
{
    use BuildsIdentity;

    private object $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = $this->demoTool($this->buildIdentity('alice'));
    }

    private function demoTool(?SymfonyIdentityResolver $identity): object
    {
        return new class(null, $identity) extends AbstractSymfonyTool
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
                return SymfonyIdentityResolver::CHAT_ROLE;
            }

            protected function plan(array $input): array
            {
                $forbidden = $this->guardCapability('run the demo tool');

                if ($forbidden !== null) {
                    return ['input' => $input, 'result' => $forbidden];
                }

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

            public function callTruncate(string $text): string
            {
                return $this->truncate($text);
            }
        };
    }

    public function test_text_under_the_cap_is_returned_unchanged(): void
    {
        self::assertSame('short output', $this->tool->callTruncate('short output'));
    }

    public function test_text_exactly_at_the_cap_is_returned_unchanged(): void
    {
        $text = str_repeat('a', 8192);

        self::assertSame($text, $this->tool->callTruncate($text));
    }

    public function test_text_one_byte_over_the_cap_is_cut_and_marked(): void
    {
        $result = $this->tool->callTruncate(str_repeat('a', 8193));

        self::assertSame(
            str_repeat('a', 8192)."\n[... output truncated at 8 KB ...]",
            $result,
        );
    }

    public function test_empty_text_is_returned_unchanged(): void
    {
        self::assertSame('', $this->tool->callTruncate(''));
    }

    public function test_the_contract_returns_the_shared_envelope_shape(): void
    {
        $decoded = json_decode($this->tool->execute(['q' => 'hi']), true);

        self::assertIsArray($decoded);
        self::assertTrue($decoded['success']);
        self::assertSame(['echo' => ['q' => 'hi']], $decoded['data']);
        self::assertSame(['mode', 'count', 'total', 'truncated'], array_keys($decoded['meta']));
        self::assertSame([], $decoded['warnings']);
    }

    public function test_an_unauthenticated_caller_is_refused(): void
    {
        $decoded = json_decode($this->demoTool($this->buildIdentity(''))->execute([]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
    }

    public function test_a_caller_with_no_identity_resolver_is_refused(): void
    {
        $decoded = json_decode($this->demoTool(null)->execute([]), true);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
    }
}
