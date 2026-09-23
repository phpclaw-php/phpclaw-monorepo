<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WordPress\Plugin;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class CapabilityDerivationTest extends TestCase
{
    use MockeryPHPUnitIntegration;

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

    public function test_an_administrator_holds_the_admin_marker(): void
    {
        $caps = $this->derive(['manage_options' => true], ['phpclaw_use_admin_chat']);

        self::assertArrayHasKey('phpclaw_use_admin_chat', $caps);
        self::assertTrue($caps['phpclaw_use_admin_chat']);
    }

    public function test_a_backend_non_administrator_does_not_hold_the_admin_marker(): void
    {
        $caps = $this->derive(['edit_posts' => true], ['phpclaw_use_admin_chat']);

        self::assertArrayNotHasKey('phpclaw_use_admin_chat', $caps);
    }

    public function test_both_backend_audiences_hold_the_tool_capability(): void
    {
        $admin = $this->derive(['manage_options' => true], ['phpclaw_use_chat']);
        $editor = $this->derive(['edit_posts' => true], ['phpclaw_use_chat']);

        self::assertTrue($admin['phpclaw_use_chat'] ?? false);
        self::assertTrue($editor['phpclaw_use_chat'] ?? false);
    }

    public function test_a_caller_outside_the_backend_holds_neither(): void
    {
        $caps = $this->derive(['read' => true], ['phpclaw_use_chat', 'phpclaw_use_admin_chat']);

        self::assertArrayNotHasKey('phpclaw_use_chat', $caps);
        self::assertArrayNotHasKey('phpclaw_use_admin_chat', $caps);
    }

    public function test_manage_all_conversations_stays_administrator_only(): void
    {
        $admin = $this->derive(['manage_options' => true], ['phpclaw_manage_all_conversations']);
        $editor = $this->derive(['edit_posts' => true], ['phpclaw_manage_all_conversations']);

        self::assertTrue($admin['phpclaw_manage_all_conversations'] ?? false);
        self::assertArrayNotHasKey('phpclaw_manage_all_conversations', $editor);
    }

    public function test_the_menu_is_registered_against_the_chat_capability(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../src/Plugin.php');

        $start = strpos($source, 'add_menu_page(');

        self::assertIsInt($start, 'add_menu_page call not found, the assertion is broken');

        $call = substr($source, $start, (int) (strpos($source, ');', $start) - $start));

        self::assertStringContainsString("capability: 'phpclaw_use_chat'", $call);
        self::assertStringNotContainsString("capability: 'manage_options'", $call);
    }

    private function derive(array $caps, array $requiredCaps): array
    {
        $filter = null;

        Functions\when('add_filter')->alias(
            static function (string $hook, callable $callback, int $priority = 10, int $accepted = 1) use (&$filter): void {
                if ($hook === 'user_has_cap') {
                    $filter = $callback;
                }
            },
        );

        $plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();

        $method = (new ReflectionClass(Plugin::class))->getMethod('registerConversationCapability');
        $method->setAccessible(true);
        $method->invoke($plugin);

        self::assertIsCallable($filter, 'the user_has_cap filter was never registered');

        return $filter($caps, $requiredCaps);
    }
}
