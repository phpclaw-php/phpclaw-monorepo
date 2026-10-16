<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Engine;

use PhpClaw\Guards\GuardRegistry;
use PhpClaw\Guards\UnicodeGuard;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Joomla\Component\Administrator\Engine\EngineBootstrapper;
use PhpClaw\Joomla\Component\Administrator\Engine\PhpClawConfig;
use PHPUnit\Framework\TestCase;

final class EngineBootstrapperTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GuardRegistry::reset();
        HookRegistry::reset();
    }

    protected function tearDown(): void
    {
        GuardRegistry::reset();
        HookRegistry::reset();
        parent::tearDown();
    }

    public function test_a_configured_guard_class_is_registered(): void
    {
        $this->invoke('registerConfigGuards', new PhpClawConfig(guards: json_encode([['class' => UnicodeGuard::class]])));

        self::assertTrue(GuardRegistry::hasClass(UnicodeGuard::class));
    }

    public function test_a_configured_class_that_is_not_a_guard_is_skipped(): void
    {
        $this->invoke('registerConfigGuards', new PhpClawConfig(guards: json_encode([['class' => \ArrayObject::class]])));

        self::assertSame(0, GuardRegistry::count());
    }

    public function test_a_configured_guard_class_that_does_not_exist_is_skipped(): void
    {
        $this->invoke('registerConfigGuards', new PhpClawConfig(guards: json_encode([['class' => 'No\\Such\\Guard']])));

        self::assertSame(0, GuardRegistry::count());
    }

    public function test_a_configured_hook_with_a_callable_handler_is_attached(): void
    {
        $this->invoke('registerConfigHooks', new PhpClawConfig(hooks: json_encode([['event' => 'tool.after', 'handler' => 'strlen']])));

        self::assertSame(1, HookRegistry::count('tool.after'));
    }

    public function test_a_configured_hook_whose_handler_cannot_be_called_is_skipped(): void
    {
        $this->invoke('registerConfigHooks', new PhpClawConfig(hooks: json_encode([['event' => 'tool.after', 'handler' => 'No\\Such::handle']])));

        self::assertSame(0, HookRegistry::count('tool.after'));
    }

    private function invoke(string $method, PhpClawConfig $config): void
    {
        (new \ReflectionMethod(EngineBootstrapper::class, $method))->invoke(new EngineBootstrapper, $config);
    }
}
