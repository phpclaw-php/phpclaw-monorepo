<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Controller\Admin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use PhpClaw\Drupal\Controller\Admin\PhpClawAnalyticsController;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class PhpClawAnalyticsControllerTest extends TestCase
{
    private function buildTime(): TimeInterface
    {
        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturnCallback(static fn () => time());

        return $time;
    }

    private function buildStatement(int|string $fieldValue): StatementInterface
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn($fieldValue);

        return $stmt;
    }

    private function buildSelectWithCount(int $count): Select
    {
        $stmt = $this->buildStatement($count);
        $select = $this->createMock(Select::class);
        $select->method('condition')->willReturnSelf();
        $select->method('countQuery')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);

        return $select;
    }

    public function test_index_returns_render_array_when_tables_do_not_exist(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $schema = $this->createMock(Schema::class);
        $schema->method('tableExists')->willReturn(false);

        $db = $this->createMock(Connection::class);
        $db->method('schema')->willReturn($schema);

        $controller = new PhpClawAnalyticsController($db, $this->buildTime());
        $result = $controller->index();

        $this->assertSame('phpclaw_admin_analytics', $result['#theme']);
        $this->assertSame(0, $result['#stats']['conversations']);
        $this->assertSame(0, $result['#stats']['messages']);
        $this->assertSame(0, $result['#stats']['active_24h']);
    }

    public function test_index_returns_correct_stats_when_tables_exist(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $schema = $this->createMock(Schema::class);
        $schema->method('tableExists')->willReturnCallback(
            fn (string $t) => in_array($t, ['phpclaw_conversations', 'phpclaw_messages'], true)
        );

        $convSelect = $this->buildSelectWithCount(42);
        $active24hSelect = $this->buildSelectWithCount(5);
        $msgSelect = $this->buildSelectWithCount(120);

        $db = $this->createMock(Connection::class);
        $db->method('schema')->willReturn($schema);
        $db->method('select')->willReturnOnConsecutiveCalls($convSelect, $active24hSelect, $msgSelect);

        $controller = new PhpClawAnalyticsController($db, $this->buildTime());
        $result = $controller->index();

        $this->assertSame(42, $result['#stats']['conversations']);
        $this->assertSame(5, $result['#stats']['active_24h']);
        $this->assertSame(120, $result['#stats']['messages']);
    }

    public function test_index_returns_zeros_when_db_throws(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $schema = $this->createMock(Schema::class);
        $schema->method('tableExists')->willThrowException(new \RuntimeException('DB unavailable'));

        $db = $this->createMock(Connection::class);
        $db->method('schema')->willReturn($schema);

        $controller = new PhpClawAnalyticsController($db, $this->buildTime());
        $result = $controller->index();

        $this->assertSame(0, $result['#stats']['conversations']);
        $this->assertSame(0, $result['#stats']['messages']);
        $this->assertSame(0, $result['#stats']['active_24h']);
    }

    public function test_index_returns_correct_theme_and_cache_keys(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $schema = $this->createMock(Schema::class);
        $schema->method('tableExists')->willReturn(false);

        $db = $this->createMock(Connection::class);
        $db->method('schema')->willReturn($schema);

        $controller = new PhpClawAnalyticsController($db, $this->buildTime());
        $result = $controller->index();

        $this->assertArrayHasKey('#attached', $result);
        $this->assertSame(0, $result['#cache']['max-age']);
    }

    public function test_create_returns_instance_from_container(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn (string $id): object => match ($id) {
            'database' => $this->createMock(Connection::class),
            'datetime.time' => $this->buildTime(),
            'logger.channel.phpclaw' => $this->createMock(LoggerInterface::class),
            default => throw new \RuntimeException("Service '{$id}' not mocked."),
        });

        $instance = PhpClawAnalyticsController::create($container);

        $this->assertInstanceOf(PhpClawAnalyticsController::class, $instance);
    }

    private function buildRecordingSelect(int $count, array &$conditions, array &$joins): Select
    {
        $stmt = $this->buildStatement($count);
        $select = $this->createMock(Select::class);
        $select->method('condition')->willReturnCallback(
            function (mixed $field, mixed $value = null, mixed $operator = null) use (&$conditions, $select): Select {
                $conditions[] = [(string) $field, $value, $operator === null ? null : (string) $operator];

                return $select;
            }
        );
        $select->method('join')->willReturnCallback(
            function (mixed $table, mixed $alias = null, mixed $condition = null) use (&$joins): string {
                $joins[] = (string) $table;

                return (string) $alias;
            }
        );
        $select->method('countQuery')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);

        return $select;
    }

    private function buildExistingSchema(): Schema
    {
        $schema = $this->createMock(Schema::class);
        $schema->method('tableExists')->willReturnCallback(
            fn (string $t) => in_array($t, ['phpclaw_conversations', 'phpclaw_messages'], true)
        );

        return $schema;
    }

    public function test_non_admin_counts_are_scoped_to_the_acting_user(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $convConditions = [];
        $activeConditions = [];
        $msgConditions = [];
        $convJoins = [];
        $activeJoins = [];
        $msgJoins = [];

        $db = $this->createMock(Connection::class);
        $db->method('schema')->willReturn($this->buildExistingSchema());
        $db->method('select')->willReturnOnConsecutiveCalls(
            $this->buildRecordingSelect(3, $convConditions, $convJoins),
            $this->buildRecordingSelect(1, $activeConditions, $activeJoins),
            $this->buildRecordingSelect(9, $msgConditions, $msgJoins),
        );

        $controller = new PhpClawAnalyticsController($db, $this->buildTime(), null, 7, false);
        $result = $controller->index();

        $this->assertSame(3, $result['#stats']['conversations']);
        $this->assertSame(1, $result['#stats']['active_24h']);
        $this->assertSame(9, $result['#stats']['messages']);

        $this->assertContains(['c.user_id', 7, '='], $convConditions);
        $this->assertContains(['c.user_id', 7, '='], $activeConditions);
        $this->assertContains(['c.user_id', 7, '='], $msgConditions);
        $this->assertSame(['phpclaw_conversations'], $msgJoins);
    }

    public function test_manage_all_counts_are_not_scoped(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $convConditions = [];
        $activeConditions = [];
        $msgConditions = [];
        $convJoins = [];
        $activeJoins = [];
        $msgJoins = [];

        $db = $this->createMock(Connection::class);
        $db->method('schema')->willReturn($this->buildExistingSchema());
        $db->method('select')->willReturnOnConsecutiveCalls(
            $this->buildRecordingSelect(61, $convConditions, $convJoins),
            $this->buildRecordingSelect(4, $activeConditions, $activeJoins),
            $this->buildRecordingSelect(139, $msgConditions, $msgJoins),
        );

        $controller = new PhpClawAnalyticsController($db, $this->buildTime(), null, 1, true);
        $result = $controller->index();

        $this->assertSame(61, $result['#stats']['conversations']);
        $this->assertSame(4, $result['#stats']['active_24h']);
        $this->assertSame(139, $result['#stats']['messages']);

        $fields = array_column(array_merge($convConditions, $activeConditions, $msgConditions), 0);
        $this->assertNotContains('c.user_id', $fields);
        $this->assertSame([], $msgJoins);
    }

    public function test_active_24h_bound_is_an_integer_timestamp(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $requestTime = 1800000000;
        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn($requestTime);

        $convConditions = [];
        $activeConditions = [];
        $msgConditions = [];
        $convJoins = [];
        $activeJoins = [];
        $msgJoins = [];

        $db = $this->createMock(Connection::class);
        $db->method('schema')->willReturn($this->buildExistingSchema());
        $db->method('select')->willReturnOnConsecutiveCalls(
            $this->buildRecordingSelect(0, $convConditions, $convJoins),
            $this->buildRecordingSelect(0, $activeConditions, $activeJoins),
            $this->buildRecordingSelect(0, $msgConditions, $msgJoins),
        );

        $controller = new PhpClawAnalyticsController($db, $time, null, 1, true);
        $controller->index();

        $this->assertContains(['c.updated_at', $requestTime - 86400, '>='], $activeConditions);
        $this->assertSame([], $convConditions);
    }

    public function test_render_array_carries_the_manage_all_flag(): void
    {
        if (! class_exists(ControllerBase::class)) {
            $this->markTestSkipped('Drupal ControllerBase not available.');
        }

        $schema = $this->createMock(Schema::class);
        $schema->method('tableExists')->willReturn(false);

        $db = $this->createMock(Connection::class);
        $db->method('schema')->willReturn($schema);

        $scoped = new PhpClawAnalyticsController($db, $this->buildTime(), null, 7, false);
        $unscoped = new PhpClawAnalyticsController($db, $this->buildTime(), null, 1, true);

        $this->assertFalse($scoped->index()['#manage_all']);
        $this->assertTrue($unscoped->index()['#manage_all']);
    }
}
