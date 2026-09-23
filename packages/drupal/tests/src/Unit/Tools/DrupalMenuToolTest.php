<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Tools;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PhpClaw\Drupal\Tools\DrupalMenuTool;
use PHPUnit\Framework\TestCase;

final class DrupalMenuToolTest extends TestCase
{
    use BootsDrupalContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDrupalContainerWithUser();
    }

    private function buildSelectMock(array $rows): Select
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));

        $select = $this->createMock(Select::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('orderBy')->willReturnSelf();
        $select->method('range')->willReturnSelf();
        $select->method('countQuery')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);

        return $select;
    }

    private function buildEtmMock(): EntityTypeManagerInterface
    {
        return $this->createMock(EntityTypeManagerInterface::class);
    }

    public function test_name_returns_correct_value(): void
    {
        $tool = new DrupalMenuTool($this->createMock(Connection::class), $this->buildEtmMock());
        $this->assertSame('drupal_menus', $tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $tool = new DrupalMenuTool($this->createMock(Connection::class), $this->buildEtmMock());

        self::assertStringContainsString(
            'Read Drupal navigation menus',
            $tool->description(),
        );
    }

    public function test_input_schema_has_expected_properties(): void
    {
        $tool = new DrupalMenuTool($this->createMock(Connection::class), $this->buildEtmMock());
        $schema = $tool->inputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('menu', $schema['properties']);
        $this->assertArrayHasKey('required', $schema);
        $this->assertSame([], $schema['required']);
    }

    public function test_execute_returns_json_with_expected_keys(): void
    {
        $rows = [
            [
                'id' => 1,
                'title' => 'Home',
                'link__uri' => 'internal:/',
                'link__title' => 'Home',
                'menu_name' => 'main',
                'weight' => 0,
                'enabled' => 1,
                'parent' => '',
            ],
            [
                'id' => 2,
                'title' => 'About',
                'link__uri' => 'internal:/about',
                'link__title' => 'About',
                'menu_name' => 'main',
                'weight' => 1,
                'enabled' => 1,
                'parent' => '',
            ],
        ];

        $select = $this->buildSelectMock($rows);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($select);

        $tool = new DrupalMenuTool($db, $this->buildEtmMock());
        $result = $tool->execute(['menu' => 'main']);

        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('menu', $decoded['meta']);
        $this->assertArrayHasKey('links', $decoded['data']);
        $this->assertArrayHasKey('total', $decoded['meta']);
        $this->assertSame('main', $decoded['meta']['menu']);
        $this->assertCount(2, $decoded['data']['links']);
    }

    public function test_execute_link_has_expected_structure(): void
    {
        $rows = [
            [
                'id' => 5,
                'title' => 'Contact',
                'link__uri' => 'internal:/contact',
                'link__title' => 'Contact',
                'menu_name' => 'footer',
                'weight' => 0,
                'enabled' => 0,
                'parent' => 'menu_link_content:abc',
            ],
        ];

        $select = $this->buildSelectMock($rows);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($select);

        $tool = new DrupalMenuTool($db, $this->buildEtmMock());
        $result = $tool->execute(['menu' => 'footer']);

        $decoded = json_decode($result, true);
        $link = $decoded['data']['links'][0];

        $this->assertSame(5, $link['id']);
        $this->assertSame('Contact', $link['title']);
        $this->assertSame('internal:/contact', $link['url']);
        $this->assertFalse($link['enabled']);
        $this->assertSame(0, $link['weight']);
        $this->assertSame('menu_link_content:abc', $link['parent']);
    }

    public function test_execute_empty_menu_returns_zero_links(): void
    {
        $select = $this->buildSelectMock([]);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($select);

        $tool = new DrupalMenuTool($db, $this->buildEtmMock());
        $result = $tool->execute(['menu' => 'nonexistent']);

        $decoded = json_decode($result, true);
        $this->assertSame([], $decoded['data']['links']);
        $this->assertSame(0, $decoded['meta']['total']);
    }

    public function test_execute_unwraps_array_wrapped_input_value(): void
    {
        $rows = [
            [
                'id' => 1,
                'title' => 'Home',
                'link__uri' => 'internal:/',
                'link__title' => 'Home',
                'menu_name' => 'main',
                'weight' => 0,
                'enabled' => 1,
                'parent' => '',
            ],
        ];

        $select = $this->buildSelectMock($rows);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($select);

        $tool = new DrupalMenuTool($db, $this->buildEtmMock());
        $result = $tool->execute(['menu' => ['main']]);

        $decoded = json_decode($result, true);
        $this->assertSame('main', $decoded['meta']['menu']);
        $this->assertCount(1, $decoded['data']['links']);
    }

    private function buildLinkCountsSelectMock(array $countRows): Select
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(...array_merge($countRows, [false]));

        $select = $this->createMock(Select::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('groupBy')->willReturnSelf();
        $select->method('addExpression')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);

        return $select;
    }

    public function test_execute_with_whitespace_only_menu_name_lists_all_menus(): void
    {
        $menu = new class
        {
            public function id(): string
            {
                return 'main';
            }

            public function label(): string
            {
                return 'Main navigation';
            }

            public function getDescription(): string
            {
                return '';
            }
        };

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([$menu]);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('menu')->willReturn($storage);

        $select = $this->buildLinkCountsSelectMock([['menu_name' => 'main', 'cnt' => 3]]);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($select);

        $tool = new DrupalMenuTool($db, $entityTypeManager);
        $result = $tool->execute(['menu' => '   ']);

        $decoded = json_decode($result, true);
        $this->assertArrayHasKey('menus', $decoded['data']);
        $this->assertArrayHasKey('total', $decoded['meta']);
        $this->assertSame(3, $decoded['data']['menus'][0]['link_count']);
    }

    public function test_execute_empty_input_array_calls_list_menus(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([]);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('menu')->willReturn($storage);

        $select = $this->buildLinkCountsSelectMock([]);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($select);

        $tool = new DrupalMenuTool($db, $entityTypeManager);

        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('menus', $decoded['data']);
        $this->assertSame(0, $decoded['meta']['total']);
    }

    private function buildLinkDb(array $rows, int $total): Connection
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(...array_merge($rows, [false]));

        $select = $this->createMock(Select::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('orderBy')->willReturnSelf();
        $select->method('range')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);

        $countStmt = $this->createMock(StatementInterface::class);
        $countStmt->method('fetchField')->willReturn($total);

        $countSelect = $this->createMock(Select::class);
        $countSelect->method('condition')->willReturnSelf();
        $countSelect->method('countQuery')->willReturnSelf();
        $countSelect->method('execute')->willReturn($countStmt);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturnOnConsecutiveCalls($select, $countSelect);

        return $db;
    }

    public function test_forbidden_when_the_caller_lacks_the_permission(): void
    {
        $this->bootDrupalContainerWithUser(2, allPermissions: false);
        $tool = new DrupalMenuTool($this->createMock(Connection::class), $this->buildEtmMock());

        $decoded = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertStringContainsString('use phpclaw chat', $decoded['error']['message']);
    }

    public function test_refuses_cleanly_when_menu_link_content_is_absent(): void
    {
        $handler = $this->createMock(ModuleHandlerInterface::class);
        $handler->method('moduleExists')->willReturn(false);

        $decoded = json_decode(
            (new DrupalMenuTool($this->createMock(Connection::class), $this->buildEtmMock(), $handler))->execute([]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('MODULE_NOT_INSTALLED', $decoded['error']['code']);
        self::assertSame('menu_link_content', $decoded['error']['module']);
    }

    public function test_schema_mode_declares_no_untrusted_field(): void
    {
        $decoded = json_decode(
            (new DrupalMenuTool($this->createMock(Connection::class), $this->buildEtmMock(), null))->execute(['schema' => true]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('schema', $decoded['meta']['mode']);
        self::assertSame([], $decoded['data']['untrusted_columns']);
        self::assertCount(3, $decoded['data']['examples']);
    }

    public function test_link_total_comes_from_a_count_not_from_the_page(): void
    {
        $rows = [['id' => 1, 'title' => 'Home', 'link__uri' => 'internal:/', 'link__title' => '', 'menu_name' => 'main', 'weight' => 0, 'enabled' => 1, 'parent' => '']];

        $decoded = json_decode(
            (new DrupalMenuTool($this->buildLinkDb($rows, 42), $this->buildEtmMock(), null))->execute(['menu' => 'main', 'limit' => 1]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('links', $decoded['meta']['mode']);
        self::assertSame(42, $decoded['meta']['total']);
        self::assertSame(1, $decoded['meta']['count']);
        self::assertTrue($decoded['meta']['has_more']);
        self::assertSame(1, $decoded['meta']['next_offset']);
    }

    public function test_a_full_last_page_does_not_claim_more(): void
    {
        $rows = [['id' => 1, 'title' => 'Home', 'link__uri' => 'internal:/', 'link__title' => '', 'menu_name' => 'main', 'weight' => 0, 'enabled' => 1, 'parent' => '']];

        $decoded = json_decode(
            (new DrupalMenuTool($this->buildLinkDb($rows, 1), $this->buildEtmMock(), null))->execute(['menu' => 'main', 'limit' => 1]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(1, $decoded['meta']['total']);
        self::assertFalse($decoded['meta']['has_more'], 'a full page that exhausts the set has no next page');
        self::assertNull($decoded['meta']['next_offset']);
    }

    public function test_unknown_argument_is_refused(): void
    {
        $decoded = json_decode(
            (new DrupalMenuTool($this->createMock(Connection::class), $this->buildEtmMock(), null))->execute(['nope' => 1]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
    }

    private function buildEtmWithMenus(array $menus): EntityTypeManagerInterface
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn($menus);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getStorage')->willReturn($storage);

        return $etm;
    }

    private function buildMenu(string $id, string $label): object
    {
        return new class($id, $label)
        {
            public function __construct(private string $id, private string $label) {}

            public function id(): string
            {
                return $this->id;
            }

            public function label(): string
            {
                return $this->label;
            }

            public function getDescription(): string
            {
                return '';
            }
        };
    }

    public function test_menu_list_order_does_not_depend_on_storage_order(): void
    {
        $menus = [
            'zebra' => $this->buildMenu('zebra', 'Zebra'),
            'mango' => $this->buildMenu('mango', 'Mango'),
            'apple' => $this->buildMenu('apple', 'Apple'),
        ];

        $countStmt = $this->createMock(StatementInterface::class);
        $countStmt->method('fetchAssoc')->willReturn(false);

        $countSelect = $this->createMock(Select::class);
        $countSelect->method('fields')->willReturnSelf();
        $countSelect->method('condition')->willReturnSelf();
        $countSelect->method('groupBy')->willReturnSelf();
        $countSelect->method('execute')->willReturn($countStmt);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($countSelect);

        $decoded = json_decode(
            (new DrupalMenuTool($db, $this->buildEtmWithMenus($menus), null))->execute(['limit' => 2]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['apple', 'mango'],
            array_column($decoded['data']['menus'], 'id'),
            'the menu page must be a slice of a sorted set, not of whatever order storage returned',
        );
        self::assertSame(3, $decoded['meta']['total']);
        self::assertTrue($decoded['meta']['has_more']);
    }
}
