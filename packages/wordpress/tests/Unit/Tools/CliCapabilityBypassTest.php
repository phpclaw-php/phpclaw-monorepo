<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\Concerns\HasToolExecutionContract;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(HasToolExecutionContract::class)]
final class CliCapabilityBypassTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function probe(): object
    {
        return new class
        {
            use HasToolExecutionContract;

            public function name(): string
            {
                return 'probe';
            }

            public function requiredCapability(): string
            {
                return 'manage_options';
            }

            public function attempt(): ?string
            {
                return $this->guardCapability('probe the guard');
            }

            protected function plan(array $input): array
            {
                return ['input' => $input, 'result' => null];
            }

            protected function perform(array $input): array
            {
                return [];
            }

            protected function verify(array $execution, array $input): array
            {
                return ['result' => null];
            }

            protected function complete(array $execution, array $input): string
            {
                return $this->success([], []);
            }
        };
    }

    public function test_the_guard_blocks_a_denied_capability_off_the_cli_path(): void
    {
        self::assertFalse(
            defined('WP_CLI'),
            'this positive control is meaningless once WP_CLI is defined for the process',
        );

        $this->denyAllCapabilities();

        $result = $this->probe()->attempt();

        self::assertIsString($result, 'the guard must reject when the capability is denied');
        self::assertSame('FORBIDDEN', json_decode($result, true)['error']['code']);
    }

    public function test_the_guard_allows_a_granted_capability_off_the_cli_path(): void
    {
        $this->grantCapability('manage_options');

        self::assertNull($this->probe()->attempt());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_guard_stands_down_under_wp_cli(): void
    {
        Monkey\setUp();
        $this->denyAllCapabilities();

        $blockedBefore = $this->probe()->attempt();

        self::assertIsString(
            $blockedBefore,
            'the same denied capability must block before WP_CLI is defined, or this proves nothing',
        );
        self::assertSame('FORBIDDEN', json_decode($blockedBefore, true)['error']['code']);

        define('WP_CLI', true);

        self::assertNull(
            $this->probe()->attempt(),
            'WP-CLI runs at user 0, so the guard must stand down there',
        );
    }
}
