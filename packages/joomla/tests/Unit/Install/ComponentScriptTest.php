<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Install;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\Mysqli\MysqliQuery;
use PHPUnit\Framework\TestCase;

final class ComponentScriptTest extends TestCase
{
    private const GROUPS = [
        ['id' => 6, 'title' => 'Manager'],
        ['id' => 7, 'title' => 'Administrator'],
        ['id' => 8, 'title' => 'Super Users'],
    ];

    private array $groups = self::GROUPS;

    private ?string $storedRules = null;

    private array $updates = [];

    protected function setUp(): void
    {
        $this->groups = self::GROUPS;
        $this->storedRules = null;
        $this->updates = [];

        $db = $this->createMock(DatabaseInterface::class);
        $db->method('getQuery')->willReturnCallback(static fn (): MysqliQuery => new MysqliQuery);
        $db->method('quoteName')->willReturnCallback(static fn (mixed $name): mixed => $name);
        $db->method('quote')->willReturnCallback(static fn (mixed $value): string => "'".$value."'");
        $db->method('setQuery')->willReturnCallback(function ($query) use ($db) {
            if (str_starts_with(trim((string) $query), 'UPDATE')) {
                $this->updates[] = (string) $query;
            }

            return $db;
        });
        $db->method('loadObjectList')->willReturnCallback(fn (): array => array_map(static fn (array $row): object => (object) $row, $this->groups));
        $db->method('loadResult')->willReturnCallback(fn (): ?string => $this->storedRules);
        $db->method('execute')->willReturn(true);

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

    public function test_install_grants_the_default_actions_to_the_three_groups(): void
    {
        $this->script()->postflight('install', new InstallerAdapter);

        self::assertSame(
            ['core.manage' => [6 => 1], 'phpclaw.chat.use' => [6 => 1, 7 => 1, 8 => 1], 'phpclaw.chat.manageall' => [7 => 1, 8 => 1]],
            $this->writtenRules(),
        );
    }

    public function test_an_existing_rule_for_a_group_is_kept(): void
    {
        $this->storedRules = '{"phpclaw.chat.use":{"6":0},"core.admin":{"8":1}}';

        $this->script()->postflight('update', new InstallerAdapter);

        $rules = $this->writtenRules();
        self::assertSame(0, $rules['phpclaw.chat.use'][6]);
        self::assertSame(1, $rules['phpclaw.chat.use'][7]);
        self::assertSame([8 => 1], $rules['core.admin']);
    }

    public function test_nothing_is_written_when_every_default_is_already_granted(): void
    {
        $this->storedRules = '{"core.manage":{"6":1},"phpclaw.chat.use":{"6":1,"7":1,"8":1},"phpclaw.chat.manageall":{"7":1,"8":1}}';

        $this->script()->postflight('install', new InstallerAdapter);

        self::assertSame([], $this->updates);
    }

    public function test_a_missing_group_is_skipped_and_the_others_are_granted(): void
    {
        $this->groups = [['id' => 8, 'title' => 'super users']];

        $this->script()->postflight('discover_install', new InstallerAdapter);

        self::assertSame(
            ['core.manage' => [], 'phpclaw.chat.use' => [8 => 1], 'phpclaw.chat.manageall' => [8 => 1]],
            $this->writtenRules(),
        );
    }

    public function test_unreadable_stored_rules_start_from_empty(): void
    {
        $this->storedRules = 'not json';

        $this->script()->postflight('install', new InstallerAdapter);

        self::assertSame([6 => 1], $this->writtenRules()['core.manage']);
    }

    public function test_uninstall_writes_nothing(): void
    {
        $this->script()->postflight('uninstall', new InstallerAdapter);

        self::assertSame([], $this->updates);
    }

    private function writtenRules(): array
    {
        self::assertCount(1, $this->updates);
        preg_match("/rules = '(.*)'\s*WHERE/s", $this->updates[0], $match);

        return json_decode($match[1], true);
    }

    private function script(): InstallerScriptInterface
    {
        if (! defined('_JEXEC')) {
            define('_JEXEC', 1);
        }

        return require dirname(__DIR__, 3).'/component/script.php';
    }
}
