<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Guards;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\Contracts\RawInputGuardInterface;
use PhpClaw\Guards\GuardRegistry;
use PHPUnit\Framework\TestCase;

final class GuardRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        GuardRegistry::reset();
    }

    protected function tearDown(): void
    {
        GuardRegistry::reset();
    }

    public function test_empty_registry_passes_any_message(): void
    {
        $this->expectNotToPerformAssertions();
        GuardRegistry::scan('ignore previous instructions');
    }

    public function test_count_is_zero_after_reset(): void
    {
        GuardRegistry::register($this->makeBlockingGuard());
        GuardRegistry::reset();
        $this->assertSame(0, GuardRegistry::count());
    }

    public function test_registered_blocking_guard_throws(): void
    {
        GuardRegistry::register($this->makeBlockingGuard());
        $this->expectException(GuardException::class);
        GuardRegistry::scan('any message');
    }

    public function test_registered_passing_guard_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        GuardRegistry::register($this->makePassingGuard());
        GuardRegistry::scan('any message');
    }

    public function test_count_increments_on_register(): void
    {
        $guardA = new class implements GuardInterface
        {
            public function scan(string $message): void {}
        };
        $guardB = new class implements GuardInterface
        {
            public function scan(string $message): void {}
        };
        GuardRegistry::register($guardA);
        GuardRegistry::register($guardB);
        $this->assertSame(2, GuardRegistry::count());
    }

    public function test_register_dedups_same_class(): void
    {
        GuardRegistry::register($this->makePassingGuard());
        GuardRegistry::register($this->makePassingGuard());
        $this->assertSame(1, GuardRegistry::count());
    }

    public function test_register_skips_duplicate_class_by_default_and_logs(): void
    {
        $output = $this->captureErrorLog(function (): void {
            GuardRegistry::register($this->makePassingGuard());
            GuardRegistry::register($this->makePassingGuard(), 10, replace: false);
        });

        $this->assertSame(1, GuardRegistry::count());
        $this->assertStringContainsString('already registered', $output);
        $this->assertStringContainsString('replace: true', $output);
    }

    public function test_register_with_replace_true_swaps_the_existing_instance_of_the_same_class(): void
    {
        GuardRegistry::register($this->makeConfigurableGuard(shouldBlock: false));
        $this->assertSame(1, GuardRegistry::count());
        GuardRegistry::scan('any message');

        GuardRegistry::register($this->makeConfigurableGuard(shouldBlock: true), 10, replace: true);
        $this->assertSame(1, GuardRegistry::count());

        $this->expectException(GuardException::class);
        GuardRegistry::scan('any message');
    }

    public function test_extra_guards_style_replace_overrides_a_default_of_the_same_class(): void
    {
        GuardRegistry::register($this->makeConfigurableGuard(shouldBlock: false));
        $countBefore = GuardRegistry::count();

        GuardRegistry::register($this->makeConfigurableGuard(shouldBlock: true), 10, replace: true);

        $this->assertSame($countBefore, GuardRegistry::count());
        $this->expectException(GuardException::class);
        GuardRegistry::scan('any message');
    }

    private function makeConfigurableGuard(bool $shouldBlock): GuardInterface
    {
        return new class($shouldBlock) implements GuardInterface
        {
            public function __construct(private readonly bool $shouldBlock) {}

            public function scan(string $message): void
            {
                if ($this->shouldBlock) {
                    throw new GuardException('Blocked by configurable test guard');
                }
            }
        };
    }

    public function test_lower_priority_guard_runs_first(): void
    {
        $callOrder = [];

        $guardA = new class($callOrder, 'A') implements GuardInterface
        {
            public function __construct(private array &$order, private string $name) {}

            public function scan(string $message): void
            {
                $this->order[] = $this->name;
            }
        };

        $guardB = new class($callOrder, 'B') implements GuardInterface
        {
            public function __construct(private array &$order, private string $name) {}

            public function scan(string $message): void
            {
                $this->order[] = $this->name;
            }
        };

        GuardRegistry::register($guardB, 20);
        GuardRegistry::register($guardA, 5);

        GuardRegistry::scan('hello');

        $this->assertSame(['A', 'B'], $callOrder);
    }

    public function test_first_blocking_guard_stops_chain(): void
    {
        $secondGuardCalled = false;

        $secondGuard = new class($secondGuardCalled) implements GuardInterface
        {
            public function __construct(private bool &$called) {}

            public function scan(string $message): void
            {
                $this->called = true;
            }
        };

        GuardRegistry::register($this->makeBlockingGuard(), 1);
        GuardRegistry::register($secondGuard, 2);

        try {
            GuardRegistry::scan('any message');
        } catch (GuardException) {
        }

        $this->assertFalse($secondGuardCalled, 'Second guard should not have been called after first threw');
    }

    public function test_reset_clears_all_guards(): void
    {
        GuardRegistry::register($this->makeBlockingGuard());
        GuardRegistry::reset();

        $this->expectNotToPerformAssertions();
        GuardRegistry::scan('any message');
    }

    public function test_default_priority_is_10(): void
    {
        $callOrder = [];

        $guardDefault = new class($callOrder, 'default') implements GuardInterface
        {
            public function __construct(private array &$order, private string $name) {}

            public function scan(string $message): void
            {
                $this->order[] = $this->name;
            }
        };

        $guardEarlier = new class($callOrder, 'earlier') implements GuardInterface
        {
            public function __construct(private array &$order, private string $name) {}

            public function scan(string $message): void
            {
                $this->order[] = $this->name;
            }
        };

        GuardRegistry::register($guardDefault);
        GuardRegistry::register($guardEarlier, 5);

        GuardRegistry::scan('hello');

        $this->assertSame(['earlier', 'default'], $callOrder);
    }

    public function test_register_defaults_is_idempotent_and_survives_a_manual_guard_first(): void
    {
        GuardRegistry::registerDefaults();
        $defaultCount = GuardRegistry::count();
        $this->assertGreaterThan(0, $defaultCount);

        GuardRegistry::registerDefaults();
        $this->assertSame($defaultCount, GuardRegistry::count());

        GuardRegistry::reset();
        GuardRegistry::register($this->makePassingGuard());
        GuardRegistry::registerDefaults();
        $this->assertSame($defaultCount + 1, GuardRegistry::count());
    }

    public function test_raw_input_guard_scans_user_message_not_augmented(): void
    {
        $seen = [];

        GuardRegistry::register(new class($seen) implements GuardInterface
        {
            public function __construct(private array &$seen) {}

            public function scan(string $message): void
            {
                $this->seen['content'] = $message;
            }
        });

        GuardRegistry::register(new class($seen) implements RawInputGuardInterface
        {
            public function __construct(private array &$seen) {}

            public function scan(string $message): void
            {
                $this->seen['raw'] = $message;
            }
        });

        GuardRegistry::scan('[Skill context] big injected template [Message] hi', 'hi');

        $this->assertSame('[Skill context] big injected template [Message] hi', $seen['content']);
        $this->assertSame('hi', $seen['raw']);
    }

    public function test_raw_input_guard_falls_back_to_message_when_no_user_message(): void
    {
        $seen = null;

        GuardRegistry::register(new class($seen) implements RawInputGuardInterface
        {
            public function __construct(private ?string &$seen) {}

            public function scan(string $message): void
            {
                $this->seen = $message;
            }
        });

        GuardRegistry::scan('only-arg');

        $this->assertSame('only-arg', $seen);
    }

    private function makeBlockingGuard(): GuardInterface
    {
        return new class implements GuardInterface
        {
            public function scan(string $message): void
            {
                throw new GuardException('Blocked by test guard');
            }
        };
    }

    private function makePassingGuard(): GuardInterface
    {
        return new class implements GuardInterface
        {
            public function scan(string $message): void {}
        };
    }

    private function captureErrorLog(callable $fn): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'phpclaw-guard-test');
        $prev = ini_get('error_log');
        ini_set('error_log', $file);
        try {
            $fn();
        } finally {
            ini_set('error_log', $prev === false ? '' : $prev);
        }
        $contents = (string) file_get_contents($file);
        @unlink($file);

        return $contents;
    }
}
