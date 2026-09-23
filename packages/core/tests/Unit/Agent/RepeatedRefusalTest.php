<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Agent;

use PhpClaw\Agent\Agent;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Providers\Contracts\ProviderInterface;
use PhpClaw\Tools\Contracts\ToolInterface;
use PhpClaw\Tools\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class RepeatedRefusalTest extends TestCase
{
    protected function setUp(): void
    {
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        HookRegistry::reset();
    }

    public function test_a_repeated_identical_refusal_ends_the_run(): void
    {
        $tool = $this->blockedTool();
        $provider = $this->scriptedProvider([
            $this->call('blocked_read', ['path' => 'configuration.php']),
            $this->call('blocked_read', ['path' => 'configuration.php']),
            $this->call('blocked_read', ['path' => 'configuration.php']),
        ]);

        $response = $this->agent($provider, [$tool])->run('read the config');

        self::assertStringContainsString('is blocked', $response->text);
        self::assertSame(2, $tool->calls, 'the loop must stop after the second identical refusal');
    }

    public function test_a_pivot_after_one_refusal_is_not_blocked(): void
    {
        $blocked = $this->blockedTool();
        $other = $this->workingTool();
        $provider = $this->scriptedProvider([
            $this->call('blocked_read', ['path' => 'configuration.php']),
            $this->call('site_info', []),
        ]);

        $response = $this->agent($provider, [$blocked, $other])->run('what database does this site use');

        self::assertSame('done', $response->text, 'the model must be free to try another tool');
        self::assertSame(1, $blocked->calls);
        self::assertSame(1, $other->calls);
    }

    public function test_a_correctable_error_still_retries_with_new_arguments(): void
    {
        $tool = $this->pickyTool();
        $provider = $this->scriptedProvider([
            $this->call('picky', ['limit' => 'ten']),
            $this->call('picky', ['limit' => 10]),
        ]);

        $response = $this->agent($provider, [$tool])->run('list ten things');

        self::assertSame('done', $response->text);
        self::assertSame(2, $tool->calls, 'a different input must not count as a repeat');
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function call(string $name, array $input): array
    {
        return [
            'type' => 'tool_use_batch',
            'calls' => [['tool_use_id' => uniqid('tu', true), 'tool_name' => $name, 'tool_input' => $input]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $script
     */
    private function scriptedProvider(array $script): ProviderInterface
    {
        return new class($script) implements ProviderInterface
        {
            private int $step = 0;

            public function __construct(private readonly array $script) {}

            public function name(): string
            {
                return 'scripted';
            }

            public function model(): string
            {
                return 'scripted-model';
            }

            public function send(array $messages, array $tools = []): array
            {
                $next = $this->script[$this->step] ?? ['type' => 'text', 'text' => 'done'];
                $this->step++;

                return $next;
            }

            public function stream(array $messages, callable $onToken): string
            {
                return 'done';
            }
        };
    }

    /**
     * @param  array<int, ToolInterface>  $tools
     */
    private function agent(ProviderInterface $provider, array $tools): Agent
    {
        $registry = new ToolRegistry;
        $registry->register($tools);

        return new Agent(provider: $provider, tools: $registry, maxIterations: 8);
    }

    private function blockedTool(): ToolInterface
    {
        return new class implements ToolInterface
        {
            public int $calls = 0;

            public function name(): string
            {
                return 'blocked_read';
            }

            public function description(): string
            {
                return 'reads a file';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]];
            }

            public function execute(array $input): string
            {
                $this->calls++;

                throw new ToolException("Access to 'configuration.php' is blocked.");
            }
        };
    }

    private function workingTool(): ToolInterface
    {
        return new class implements ToolInterface
        {
            public int $calls = 0;

            public function name(): string
            {
                return 'site_info';
            }

            public function description(): string
            {
                return 'site info';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => []];
            }

            public function execute(array $input): string
            {
                $this->calls++;

                return '{"success":true,"data":{"db":"mysql"},"meta":{},"warnings":[]}';
            }
        };
    }

    private function pickyTool(): ToolInterface
    {
        return new class implements ToolInterface
        {
            public int $calls = 0;

            public function name(): string
            {
                return 'picky';
            }

            public function description(): string
            {
                return 'wants an int limit';
            }

            public function inputSchema(): array
            {
                return ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]];
            }

            public function execute(array $input): string
            {
                $this->calls++;

                if (! is_int($input['limit'] ?? null)) {
                    throw new ToolException('"limit" must be an integer.');
                }

                return '{"success":true,"data":{"rows":[]},"meta":{},"warnings":[]}';
            }
        };
    }
}
