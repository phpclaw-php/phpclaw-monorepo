<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Install;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;
use PHPUnit\Framework\TestCase;

final class PackageScriptTest extends TestCase
{
    private array $updates = [];

    private array $sets = [];

    private array $wheres = [];

    private int $executes = 0;

    protected function setUp(): void
    {
        $this->updates = [];
        $this->sets = [];
        $this->wheres = [];
        $this->executes = 0;

        $query = $this->createMock(QueryInterface::class);
        $query->method('update')->willReturnCallback(function (mixed $table) use (&$query): QueryInterface {
            $this->updates[] = (string) $table;

            return $query;
        });
        $query->method('set')->willReturnCallback(function (mixed $clause) use (&$query): QueryInterface {
            $this->sets[] = (string) $clause;

            return $query;
        });
        $query->method('where')->willReturnCallback(function (mixed $clause) use (&$query): QueryInterface {
            $this->wheres[] = (string) $clause;

            return $query;
        });

        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getQuery')->willReturn($query);
        $db->method('quoteName')->willReturnCallback(fn (mixed $n) => (string) $n);
        $db->method('quote')->willReturnCallback(fn (mixed $v) => "'".(string) $v."'");
        $db->method('setQuery')->willReturnSelf();
        $db->method('execute')->willReturnCallback(function (): bool {
            $this->executes++;

            return true;
        });

        Factory::$container = new class($db)
        {
            public function __construct(private DatabaseInterface $db) {}

            public function get(string $id): DatabaseInterface
            {
                return $this->db;
            }
        };
    }

    protected function tearDown(): void
    {
        Factory::$container = null;
    }

    public function test_postflight_enables_the_system_plugin(): void
    {
        $this->script()->postflight('install', new InstallerAdapter);

        self::assertContains("folder = 'system'", $this->wheres);
        self::assertContains("element = 'phpclaw'", $this->wheres);
        self::assertContains("type = 'plugin'", $this->wheres);
    }

    public function test_postflight_enables_the_webservices_plugin(): void
    {
        $this->script()->postflight('install', new InstallerAdapter);

        self::assertContains("folder = 'webservices'", $this->wheres);
    }

    public function test_postflight_writes_enabled_one_to_the_extensions_table(): void
    {
        $this->script()->postflight('install', new InstallerAdapter);

        self::assertSame(['#__extensions', '#__extensions'], $this->updates);
        self::assertSame(['enabled = 1', 'enabled = 1'], $this->sets);
    }

    public function test_postflight_runs_exactly_two_statements(): void
    {
        $this->script()->postflight('install', new InstallerAdapter);

        self::assertSame(2, $this->executes);
    }

    public function test_postflight_also_runs_on_update(): void
    {
        $this->script()->postflight('update', new InstallerAdapter);

        self::assertSame(2, $this->executes);
    }

    public function test_postflight_also_runs_on_discover_install(): void
    {
        $this->script()->postflight('discover_install', new InstallerAdapter);

        self::assertSame(2, $this->executes);
    }

    public function test_postflight_touches_nothing_on_uninstall(): void
    {
        $this->script()->postflight('uninstall', new InstallerAdapter);

        self::assertSame(0, $this->executes);
        self::assertSame([], $this->wheres);
    }

    public function test_the_other_hooks_succeed_without_touching_the_database(): void
    {
        $script = $this->script();
        $adapter = new InstallerAdapter;

        self::assertTrue($script->preflight('install', $adapter));
        self::assertTrue($script->install($adapter));
        self::assertTrue($script->update($adapter));
        self::assertTrue($script->uninstall($adapter));
        self::assertSame(0, $this->executes);
    }

    private function script(): InstallerScriptInterface
    {
        if (! defined('_JEXEC')) {
            define('_JEXEC', 1);
        }

        return require __DIR__.'/../../../script.php';
    }
}
