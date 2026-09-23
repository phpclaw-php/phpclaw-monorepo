<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Engine;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Event\Event;
use PhpClaw\Joomla\Component\Administrator\Engine\JoomlaEventDispatcher;
use PHPUnit\Framework\TestCase;

final class JoomlaEventDispatcherTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::$application = null;
        Factory::$container = null;
    }

    public function test_db_resolves_database_interface_from_joomla_container(): void
    {
        $db = $this->createMock(DatabaseInterface::class);

        Factory::$container = new class($db)
        {
            public function __construct(private DatabaseInterface $db) {}

            public function get(string $id): DatabaseInterface
            {
                return $this->db;
            }
        };

        $this->assertSame($db, JoomlaEventDispatcher::db());
    }

    public function test_fire_writes_listener_modified_argument_back_into_value(): void
    {
        $dispatcher = new Dispatcher;
        $dispatcher->addListener('onPhpClawExtraTools', static function (Event $e): void {
            $e->setArgument('tools', ['injected_tool']);
        });

        Factory::$application = new class($dispatcher)
        {
            public function __construct(private Dispatcher $dispatcher) {}

            public function getDispatcher(): Dispatcher
            {
                return $this->dispatcher;
            }
        };

        $value = ['original'];
        JoomlaEventDispatcher::fire('onPhpClawExtraTools', 'tools', $value);

        $this->assertSame(['injected_tool'], $value);
    }

    public function test_fire_does_not_throw_outside_joomla_context(): void
    {
        $value = 'original';

        JoomlaEventDispatcher::fire('onPhpClawExtraTools', 'tools', $value);

        $this->assertSame('original', $value);
    }

    public function test_fire_preserves_array_value_outside_joomla_context(): void
    {
        $value = ['tool_a', 'tool_b'];

        JoomlaEventDispatcher::fire('onPhpClawExtraTools', 'tools', $value);

        $this->assertSame(['tool_a', 'tool_b'], $value);
    }

    public function test_fire_preserves_null_value_outside_joomla_context(): void
    {
        $value = null;

        JoomlaEventDispatcher::fire('onPhpClawExtraGuards', 'guards', $value);

        $this->assertNull($value);
    }

    public function test_fire_accepts_empty_event_name_without_throw(): void
    {
        $value = 42;

        JoomlaEventDispatcher::fire('', 'key', $value);

        $this->assertSame(42, $value);
    }

    public function test_db_method_returns_database_interface_type(): void
    {
        $ref = new \ReflectionMethod(JoomlaEventDispatcher::class, 'db');
        $returnType = $ref->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertStringContainsString('DatabaseInterface', (string) $returnType);
    }

    public function test_db_method_delegates_to_joomla_container(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/component/src/Engine/JoomlaEventDispatcher.php'
        );

        $this->assertStringContainsString('Factory::getContainer()->get(DatabaseInterface::class)', $source);
    }
}
