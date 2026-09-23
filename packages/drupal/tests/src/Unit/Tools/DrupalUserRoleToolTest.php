<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Tools;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PhpClaw\Drupal\Tools\DrupalUserRoleTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DrupalUserRoleToolTest extends TestCase
{
    use BootsDrupalContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDrupalContainerWithUser();
    }

    private function buildRole(
        string $id,
        string $label,
        bool $isAdmin,
        array $permissions,
    ): object {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['id', 'label', 'isAdmin', 'getPermissions'])
            ->getMock();

        $mock->method('id')->willReturn($id);
        $mock->method('label')->willReturn($label);
        $mock->method('isAdmin')->willReturn($isAdmin);
        $mock->method('getPermissions')->willReturn($permissions);

        return $mock;
    }

    private function buildEtm(array $roles): EntityTypeManagerInterface&MockObject
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn($roles);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getStorage')->with('user_role')->willReturn($storage);

        return $etm;
    }

    private function buildDbWithCount(int $count, string $roleId = 'editor'): Connection
    {
        $countStmt = $this->createMock(StatementInterface::class);
        $countStmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(
            ['roles_target_id' => $roleId, 'cnt' => $count],
            false,
        );

        $select = $this->createMock(Select::class);
        $select->method('fields')->willReturnSelf();
        $select->method('groupBy')->willReturnSelf();
        $select->method('addExpression')->willReturnSelf();
        $select->method('execute')->willReturn($countStmt);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($select);

        return $db;
    }

    public function test_name_returns_correct_value(): void
    {
        $tool = new DrupalUserRoleTool($this->createMock(Connection::class), $this->buildEtm([]));
        $this->assertSame('drupal_roles', $tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $tool = new DrupalUserRoleTool($this->createMock(Connection::class), $this->buildEtm([]));

        self::assertStringContainsString(
            'List Drupal user roles',
            $tool->description(),
        );
    }

    public function test_input_schema_has_expected_properties(): void
    {
        $tool = new DrupalUserRoleTool($this->createMock(Connection::class), $this->buildEtm([]));
        $schema = $tool->inputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('role', $schema['properties']);
        $this->assertArrayHasKey('required', $schema);
        $this->assertSame([], $schema['required']);
    }

    public function test_role_property_has_string_type(): void
    {
        $tool = new DrupalUserRoleTool($this->createMock(Connection::class), $this->buildEtm([]));
        $schema = $tool->inputSchema();

        $this->assertSame('string', $schema['properties']['role']['type']);
    }

    public function test_role_property_has_description(): void
    {
        $tool = new DrupalUserRoleTool($this->createMock(Connection::class), $this->buildEtm([]));
        $schema = $tool->inputSchema();

        self::assertStringContainsString(
            'Get details for a specific role ID',
            $schema['properties']['role']['description'],
        );
    }

    public function test_execute_lists_all_roles(): void
    {
        $roles = [
            'anonymous' => $this->buildRole('anonymous', 'Anonymous user', false, []),
            'authenticated' => $this->buildRole('authenticated', 'Authenticated user', false, ['access content']),
            'administrator' => $this->buildRole('administrator', 'Administrator', true, ['administer site configuration', 'administer users']),
        ];

        $db = $this->buildDbWithCount(5);
        $tool = new DrupalUserRoleTool($db, $this->buildEtm($roles));

        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['success']);
        $this->assertArrayHasKey('roles', $decoded['data']);
        $this->assertSame('query', $decoded['meta']['mode']);
        $this->assertSame(3, $decoded['meta']['total']);
        $this->assertCount(3, $decoded['data']['roles']);
    }

    public function test_execute_role_has_expected_structure(): void
    {
        $roles = [
            'editor' => $this->buildRole('editor', 'Editor', false, ['edit any article content', 'create article content']),
        ];

        $db = $this->buildDbWithCount(3);
        $tool = new DrupalUserRoleTool($db, $this->buildEtm($roles));

        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $role = $decoded['data']['roles'][0];
        $this->assertSame('editor', $role['id']);
        $this->assertSame('Editor', $role['label']);
        $this->assertFalse($role['is_admin']);
        $this->assertSame(3, $role['user_count']);
        $this->assertSame(2, $role['permission_count']);
        $this->assertArrayHasKey('permissions', $role);
    }

    public function test_execute_admin_role_is_flagged(): void
    {
        $roles = [
            'administrator' => $this->buildRole('administrator', 'Administrator', true, ['administer site configuration']),
        ];

        $db = $this->buildDbWithCount(2);
        $tool = new DrupalUserRoleTool($db, $this->buildEtm($roles));

        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['data']['roles'][0]['is_admin']);
    }

    public function test_execute_permissions_sorted_alphabetically(): void
    {
        $roles = [
            'editor' => $this->buildRole('editor', 'Editor', false, ['z-perm', 'a-perm', 'm-perm']),
        ];

        $db = $this->buildDbWithCount(1);
        $tool = new DrupalUserRoleTool($db, $this->buildEtm($roles));

        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $perms = $decoded['data']['roles'][0]['permissions'];
        $this->assertSame(['a-perm', 'm-perm', 'z-perm'], $perms);
    }

    public function test_execute_specific_role_returns_full_permissions(): void
    {
        $permissions = array_map(
            static fn (int $i) => "permission-{$i}",
            range(1, 15),
        );

        $role = $this->buildRole('editor', 'Editor', false, $permissions);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')
            ->with(['editor'])
            ->willReturn(['editor' => $role]);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getStorage')->willReturn($storage);

        $db = $this->buildDbWithCount(2);
        $tool = new DrupalUserRoleTool($db, $etm);

        $result = $tool->execute(['role' => 'editor']);
        $decoded = json_decode($result, true);

        $this->assertCount(15, $decoded['data']['roles'][0]['permissions']);
    }

    public function test_execute_all_roles_permissions_sliced_to_10(): void
    {
        $permissions = array_map(
            static fn (int $i) => "permission-{$i}",
            range(1, 20),
        );

        $roles = [
            'editor' => $this->buildRole('editor', 'Editor', false, $permissions),
        ];

        $db = $this->buildDbWithCount(0);
        $tool = new DrupalUserRoleTool($db, $this->buildEtm($roles));

        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertCount(10, $decoded['data']['roles'][0]['permissions']);
        $this->assertSame(20, $decoded['data']['roles'][0]['permission_count']);
    }

    public function test_execute_empty_role_list_returns_zero_total(): void
    {
        $tool = new DrupalUserRoleTool($this->buildDbWithCount(0), $this->buildEtm([]));
        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertSame(0, $decoded['meta']['total']);
        $this->assertSame([], $decoded['data']['roles']);
    }

    public function test_execute_array_input_normalized(): void
    {
        $tool = new DrupalUserRoleTool($this->buildDbWithCount(0), $this->buildEtm([]));
        $result = $tool->execute(['role' => ['editor']]);
        $decoded = json_decode($result, true);

        $this->assertArrayHasKey('roles', $decoded['data']);
    }

    public function test_forbidden_when_the_caller_lacks_the_permission(): void
    {
        $this->bootDrupalContainerWithUser(2, allPermissions: false);
        $tool = new DrupalUserRoleTool(
            $this->createMock(Connection::class),
            $this->buildEtm([]),
        );

        $decoded = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertNull($decoded['data']);
        self::assertStringContainsString('use phpclaw chat', $decoded['error']['message']);
    }

    public function test_schema_mode_surfaces_examples_and_declares_no_untrusted_field(): void
    {
        $tool = new DrupalUserRoleTool(
            $this->createMock(Connection::class),
            $this->buildEtm([]),
        );

        $decoded = json_decode($tool->execute(['schema' => true]), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('schema', $decoded['meta']['mode']);
        self::assertFalse($decoded['meta']['database_query_performed']);
        self::assertCount(3, $decoded['data']['examples']);
        self::assertSame('use phpclaw chat', $decoded['data']['drupal_permission']);
        self::assertSame([], $decoded['data']['untrusted_columns']);
    }

    public function test_no_untrusted_warning_because_role_labels_are_admin_authored(): void
    {
        $roles = ['editor' => $this->buildRole('editor', 'Editor', false, ['access content'])];

        $decoded = json_decode(
            (new DrupalUserRoleTool($this->buildDbWithCount(1), $this->buildEtm($roles)))->execute([]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame([], $decoded['warnings']);
        self::assertArrayNotHasKey('untrusted_fields_returned', $decoded['meta']);
    }

    public function test_total_is_the_full_set_and_paging_is_a_slice(): void
    {
        $roles = [];

        foreach (['a', 'b', 'c'] as $id) {
            $roles[$id] = $this->buildRole($id, strtoupper($id), false, []);
        }

        $decoded = json_decode(
            (new DrupalUserRoleTool($this->buildDbWithCount(0), $this->buildEtm($roles)))->execute(['limit' => 2]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(3, $decoded['meta']['total']);
        self::assertSame(2, $decoded['meta']['count']);
        self::assertTrue($decoded['meta']['has_more']);
        self::assertSame(2, $decoded['meta']['next_offset']);
    }

    public function test_a_full_last_page_does_not_claim_more(): void
    {
        $roles = ['a' => $this->buildRole('a', 'A', false, [])];

        $decoded = json_decode(
            (new DrupalUserRoleTool($this->buildDbWithCount(0), $this->buildEtm($roles)))->execute(['limit' => 1]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertFalse($decoded['meta']['has_more']);
        self::assertNull($decoded['meta']['next_offset']);
    }

    public function test_the_permission_preview_is_silent_and_declared_in_schema(): void
    {
        $many = [];

        for ($i = 0; $i < 15; $i++) {
            $many[] = 'permission '.$i;
        }

        $roles = ['big' => $this->buildRole('big', 'Big', false, $many)];

        $decoded = json_decode(
            (new DrupalUserRoleTool($this->buildDbWithCount(0), $this->buildEtm($roles)))->execute([]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame([], $decoded['warnings']);
        self::assertCount(10, $decoded['data']['roles'][0]['permissions']);
        self::assertSame(15, $decoded['data']['roles'][0]['permission_count'], 'the count stays exact');
    }

    public function test_unknown_argument_is_refused(): void
    {
        $decoded = json_decode(
            (new DrupalUserRoleTool($this->createMock(Connection::class), $this->buildEtm([])))->execute(['nope' => 1]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
    }

    public function test_limit_over_the_maximum_is_refused(): void
    {
        $decoded = json_decode(
            (new DrupalUserRoleTool($this->createMock(Connection::class), $this->buildEtm([])))->execute(['limit' => 9999]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('INVALID_LIMIT', $decoded['error']['code']);
    }

    public function test_paging_is_stable_regardless_of_storage_order(): void
    {
        $roles = [
            'zebra' => $this->buildRole('zebra', 'Zebra', false, []),
            'mango' => $this->buildRole('mango', 'Mango', false, []),
            'apple' => $this->buildRole('apple', 'Apple', false, []),
        ];

        $page = json_decode(
            (new DrupalUserRoleTool($this->buildDbWithCount(0), $this->buildEtm($roles)))->execute(['limit' => 2]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(
            ['apple', 'mango'],
            array_column($page['data']['roles'], 'id'),
            'the page must be a slice of a sorted set, not of whatever order storage returned',
        );

        $second = json_decode(
            (new DrupalUserRoleTool($this->buildDbWithCount(0), $this->buildEtm($roles)))->execute(['limit' => 2, 'offset' => 2]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(['zebra'], array_column($second['data']['roles'], 'id'));
    }
}
