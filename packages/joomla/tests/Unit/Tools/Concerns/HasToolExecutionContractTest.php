<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Tools\Concerns;

use Joomla\CMS\Application\ConsoleApplication;
use Joomla\CMS\Factory;
use PhpClaw\Joomla\Component\Administrator\Tools\Concerns\HasToolExecutionContract;
use PHPUnit\Framework\TestCase;

final class HasToolExecutionContractTest extends TestCase
{
    protected function setUp(): void
    {
        Factory::$application = null;
        Factory::$applicationError = null;
    }

    protected function tearDown(): void
    {
        Factory::$application = null;
        Factory::$applicationError = null;
    }

    public function test_running_in_console_is_true_for_a_console_application(): void
    {
        Factory::$application = (new \ReflectionClass(ConsoleApplication::class))->newInstanceWithoutConstructor();

        self::assertTrue($this->subject()->isConsole());
    }

    public function test_running_in_console_is_false_for_a_web_application(): void
    {
        Factory::$application = $this->webApplication(authorised: true);

        self::assertFalse($this->subject()->isConsole());
    }

    public function test_running_in_console_is_false_when_no_application_is_booted(): void
    {
        self::assertFalse($this->subject()->isConsole());
    }

    public function test_running_in_console_is_false_when_resolving_the_application_throws(): void
    {
        Factory::$applicationError = new \RuntimeException('Application instance not set.');

        self::assertFalse(
            $this->subject()->isConsole(),
            'A cron script that bootstraps Joomla without an application must not be mistaken for the console.',
        );
    }

    public function test_caller_has_capability_is_true_when_the_identity_authorises_the_action(): void
    {
        Factory::$application = $this->webApplication(authorised: true);

        self::assertTrue($this->subject()->hasCapability('core.manage'));
    }

    public function test_caller_has_capability_is_false_when_the_identity_refuses_the_action(): void
    {
        Factory::$application = $this->webApplication(authorised: false);

        self::assertFalse($this->subject()->hasCapability('core.manage'));
    }

    public function test_caller_has_capability_is_false_when_no_application_is_booted(): void
    {
        self::assertFalse($this->subject()->hasCapability('core.manage'));
    }

    public function test_caller_has_capability_is_false_when_resolving_the_application_throws(): void
    {
        Factory::$applicationError = new \RuntimeException('Application instance not set.');

        self::assertFalse($this->subject()->hasCapability('core.manage'));
    }

    public function test_caller_has_capability_checks_the_action_against_the_tools_own_asset(): void
    {
        $seen = [];
        Factory::$application = $this->recordingApplication($seen);

        $this->subject()->hasCapability('core.manage');

        self::assertSame([['core.manage', 'com_content']], $seen);
    }

    public function test_the_guard_lets_the_console_through_without_an_acl_check(): void
    {
        Factory::$application = (new \ReflectionClass(ConsoleApplication::class))->newInstanceWithoutConstructor();

        self::assertNull($this->subject()->guard('read articles'));
    }

    public function test_the_guard_lets_an_authorised_web_caller_through(): void
    {
        Factory::$application = $this->webApplication(authorised: true);

        self::assertNull($this->subject()->guard('read articles'));
    }

    public function test_the_guard_refuses_an_unauthorised_web_caller_with_a_forbidden_envelope(): void
    {
        Factory::$application = $this->webApplication(authorised: false);

        $refusal = $this->subject()->guard('read articles');

        self::assertIsString($refusal);

        $decoded = json_decode($refusal, true);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertStringContainsString('core.manage', $decoded['error']['message']);
    }

    public function test_the_guard_refuses_when_no_application_is_booted(): void
    {
        $refusal = $this->subject()->guard('read articles');

        self::assertIsString(
            $refusal,
            'An unbooted application must fail closed: neither the console exemption nor the ACL check may pass.',
        );
    }

    private function subject(): object
    {
        return new class
        {
            use HasToolExecutionContract;

            public function isConsole(): bool
            {
                return $this->runningInConsole();
            }

            public function hasCapability(string $capability): bool
            {
                return $this->callerHasCapability($capability);
            }

            public function guard(string $subject): ?string
            {
                return $this->guardCapability($subject);
            }

            public function requiredCapability(): string
            {
                return 'core.manage';
            }

            protected function requiredAsset(): string
            {
                return 'com_content';
            }

            protected function plan(array $input): array
            {
                return [];
            }

            protected function perform(array $input): array
            {
                return [];
            }

            protected function verify(array $execution, array $input): array
            {
                return [];
            }

            protected function complete(array $execution, array $input): string
            {
                return '';
            }
        };
    }

    private function webApplication(bool $authorised): object
    {
        $identity = new class($authorised)
        {
            public function __construct(private readonly bool $authorised) {}

            public function authorise(string $action, ?string $asset = null): bool
            {
                return $this->authorised;
            }
        };

        return new class($identity)
        {
            public function __construct(private readonly object $identity) {}

            public function getIdentity(): object
            {
                return $this->identity;
            }
        };
    }

    private function recordingApplication(array &$seen): object
    {
        $identity = new class($seen)
        {
            public function __construct(private array &$seen) {}

            public function authorise(string $action, ?string $asset = null): bool
            {
                $this->seen[] = [$action, $asset];

                return true;
            }
        };

        return new class($identity)
        {
            public function __construct(private readonly object $identity) {}

            public function getIdentity(): object
            {
                return $this->identity;
            }
        };
    }
}
