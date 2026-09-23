<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Support;

use Joomla\Database\DatabaseDriver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MockDatabase
{
    private static array $state = [];

    public static function raw(TestCase $tc): MockObject
    {
        return self::build($tc);
    }

    public static function fluent(TestCase $tc): MockObject
    {
        return self::build($tc);
    }

    public static function enqueueAssoc(MockObject $db, ?array $row): void
    {
        self::$state[spl_object_id($db)]['assoc'][] = $row;
    }

    public static function enqueueAssocList(MockObject $db, ?array $rows): void
    {
        self::$state[spl_object_id($db)]['assocList'][] = $rows;
    }

    public static function enqueueResult(MockObject $db, mixed $value): void
    {
        self::$state[spl_object_id($db)]['result'][] = $value;
    }

    public static function enqueueColumn(MockObject $db, ?array $values): void
    {
        self::$state[spl_object_id($db)]['column'][] = $values;
    }

    public static function queries(MockObject $db): array
    {
        return self::$state[spl_object_id($db)]['queries'] ?? [];
    }

    public static function lastQuery(MockObject $db): string
    {
        $q = self::queries($db);

        return $q === [] ? '' : (string) end($q);
    }

    private static function build(TestCase $tc): MockObject
    {
        /** @var MockObject&DatabaseDriver $db */
        $db = $tc->getMockBuilder(DatabaseDriver::class)
            ->onlyMethods([
                'setQuery', 'getQuery', 'quote', 'quoteName', 'getPrefix',
                'execute', 'loadAssoc', 'loadAssocList', 'loadResult', 'loadColumn',
            ])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $id = spl_object_id($db);
        self::$state[$id] = [
            'queries' => [],
            'assoc' => [],
            'assocList' => [],
            'result' => [],
            'column' => [],
        ];

        $db->method('setQuery')->willReturnCallback(function ($q) use ($db) {
            self::$state[spl_object_id($db)]['queries'][] = (string) $q;

            return $db;
        });

        $query = new MockDatabaseQuery;
        $db->method('getQuery')->willReturn($query);

        $db->method('quote')->willReturnCallback(static function ($v) {
            if (is_array($v)) {
                return array_map(static fn ($x): string => "'".(string) $x."'", $v);
            }

            return "'".(string) $v."'";
        });

        $db->method('quoteName')->willReturnCallback(static function ($v) {
            if (is_array($v)) {
                return array_map(static fn ($x): string => "`{$x}`", $v);
            }

            return "`{$v}`";
        });

        $db->method('getPrefix')->willReturn('jos_');
        $db->method('execute')->willReturn(true);

        $db->method('loadAssoc')->willReturnCallback(static function () use ($id) {
            $q = &self::$state[$id]['assoc'];

            return $q === [] ? null : array_shift($q);
        });

        $db->method('loadAssocList')->willReturnCallback(static function () use ($id) {
            $q = &self::$state[$id]['assocList'];

            return $q === [] ? null : array_shift($q);
        });

        $db->method('loadResult')->willReturnCallback(static function () use ($id) {
            $q = &self::$state[$id]['result'];

            return $q === [] ? null : array_shift($q);
        });

        $db->method('loadColumn')->willReturnCallback(static function () use ($id) {
            $q = &self::$state[$id]['column'];

            return $q === [] ? null : array_shift($q);
        });

        return $db;
    }
}
