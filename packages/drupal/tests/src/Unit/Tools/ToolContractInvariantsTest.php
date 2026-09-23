<?php

declare(strict_types=1);

namespace Drupal\Tests\phpclaw\Unit\Tools;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PhpClaw\Drupal\Tools\DatabaseTool;
use PhpClaw\Drupal\Tools\DrupalBlockTool;
use PhpClaw\Drupal\Tools\DrupalCacheTool;
use PhpClaw\Drupal\Tools\DrupalContentModerationTool;
use PhpClaw\Drupal\Tools\DrupalCronTool;
use PhpClaw\Drupal\Tools\DrupalEntityTool;
use PhpClaw\Drupal\Tools\DrupalMediaTool;
use PhpClaw\Drupal\Tools\DrupalMenuTool;
use PhpClaw\Drupal\Tools\DrupalModuleTool;
use PhpClaw\Drupal\Tools\DrupalPathAliasTool;
use PhpClaw\Drupal\Tools\DrupalUserRoleTool;
use PhpClaw\Drupal\Tools\DrupalViewsTool;
use PhpClaw\Drupal\Tools\DrupalWebformTool;
use PhpClaw\Drupal\Tools\LogTool;
use PhpClaw\Testing\AbstractToolContractInvariants;

final class ToolContractInvariantsTest extends AbstractToolContractInvariants
{
    use BootsDrupalContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDrupalContainerWithUser();
    }

    protected function toolFiles(): array
    {
        $helpers = ['InputNormaliser', 'OutputByteCap', 'BlockedTablesPolicy'];
        $files = [];

        foreach ((array) glob(__DIR__.'/../../../../src/Tools/*.php') as $file) {
            if (in_array(basename((string) $file, '.php'), $helpers, true)) {
                continue;
            }

            $files[] = (string) $file;
        }

        return $files;
    }

    protected function pendingConversion(): array
    {
        return [
        ];
    }

    protected function collectionTools(): array
    {
        return array_keys($this->collectionSortEvidence());
    }

    private function collectionSortEvidence(): array
    {
        return [
            'DrupalPathAliasTool' => ['sql', "->orderBy('p.id'"],
            'DrupalMenuTool' => ['sql', "->orderBy('m.id', 'ASC')"],
            'DrupalMediaTool' => ['sql', "->orderBy('f.fid', 'ASC')"],
            'DrupalContentModerationTool' => ['sql', "->orderBy('cm.content_entity_type_id', 'ASC')"],
            'DrupalCronTool' => ['php', "?: strcmp(\$a['name'], \$b['name'])"],
            'DrupalCacheTool' => ['php', "?: strcmp(\$a['bin'], \$b['bin'])"],
            'LogTool' => ['sql', "->orderBy('w.wid', 'DESC')"],
            'DrupalWebformTool' => ['sql', "->orderBy('ws.sid', 'DESC')"],
            'DrupalEntityTool' => ['php', "\$query->sort('nid', 'DESC');"],
            'DrupalUserRoleTool' => ['php', 'ksort($all);'],
            'DrupalViewsTool' => ['php', 'ksort($matched);'],
            'DrupalModuleTool' => ['php', "strcmp((string) \$a['machine_name'], (string) \$b['machine_name'])"],
            'DrupalBlockTool' => ['php', "strcmp((string) \$a['id'], (string) \$b['id'])"],
        ];
    }

    protected function stableSecondarySortProblem(string $tool): ?string
    {
        [$kind, $evidence] = $this->collectionSortEvidence()[$tool];

        if ($kind !== 'sql') {
            return $this->phpSortProblem($tool);
        }

        preg_match("/orderBy\\('([^']+)'(?:, '([^']+)')?/", $evidence, $want);
        $recorded = $this->recordOrderBy($tool);

        foreach ($recorded as [$field, $direction]) {
            if ($field === ($want[1] ?? null) && ($want[2] ?? $direction) === $direction) {
                return null;
            }
        }

        return $recorded === []
            ? 'the tool issued no orderBy() at all, so nothing pins the page order'
            : 'no secondary orderBy() on the primary key after the requested column, saw: '
                .implode(', ', array_map(static fn (array $c): string => $c[0].' '.$c[1], $recorded));
    }

    private function recordOrderBy(string $tool): array
    {
        $recorded = [];

        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(false);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('fetchField')->willReturn(0);

        $select = $this->createMock(Select::class);
        foreach (['fields', 'condition', 'range', 'groupBy', 'addExpression', 'leftJoin', 'innerJoin', 'addField'] as $passthrough) {
            $select->method($passthrough)->willReturnSelf();
        }
        $select->method('orderBy')->willReturnCallback(
            function (string $field, string $direction = 'ASC') use (&$recorded, &$select): Select {
                $recorded[] = [$field, $direction];

                return $select;
            }
        );
        $select->method('countQuery')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);

        $schema = $this->createMock(Schema::class);
        $schema->method('tableExists')->willReturn(true);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($select);
        $db->method('schema')->willReturn($schema);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('loadMultiple')->willReturn([]);
        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getStorage')->willReturn($storage);

        $handler = $this->alwaysInstalled();

        $built = match ($tool) {
            'DrupalPathAliasTool' => new DrupalPathAliasTool($db),
            'DrupalMenuTool' => new DrupalMenuTool($db, $etm, $handler),
            'DrupalMediaTool' => new DrupalMediaTool($db, $handler),
            'DrupalContentModerationTool' => new DrupalContentModerationTool($db, $handler),
            'LogTool' => new LogTool($db, $handler),
            'DrupalWebformTool' => new DrupalWebformTool($db, $handler, $etm),
            default => null,
        };

        $arguments = match ($tool) {
            'DrupalMenuTool' => ['menu' => 'main'],
            'DrupalWebformTool' => ['webform_id' => 'contact'],
            default => [],
        };

        $built?->execute($arguments);

        return $recorded;
    }

    protected function freeTextFields(): array
    {
        return ['title', 'name', 'value', 'message', 'alias', 'version', 'package', 'filename'];
    }

    public function test_has_permission_appears_only_in_the_shim(): void
    {
        $shimFile = realpath(__DIR__.'/../../../../src/Tools/Concerns/HasToolExecutionContract.php');
        $offenders = [];
        $checked = 0;

        foreach ($this->toolFiles() as $file) {
            $class = 'PhpClaw\\Drupal\\Tools\\'.basename($file, '.php');

            if (! class_exists($class) || ! method_exists($class, 'callerHasCapability')) {
                continue;
            }

            $checked++;
            $declaredIn = realpath((string) (new \ReflectionMethod($class, 'callerHasCapability'))->getFileName());

            if ($declaredIn !== $shimFile) {
                $offenders[] = basename($file, '.php').' resolves callerHasCapability() to '.basename((string) $declaredIn);
            }
        }

        self::assertGreaterThan(0, $checked, 'no tool exposes callerHasCapability(), so this invariant scanned nothing');
        self::assertSame(
            [],
            $offenders,
            'the Drupal permission lookup must come from the shim only. Offenders: '.implode(', ', $offenders),
        );
    }

    public function test_no_tool_disables_entity_access(): void
    {
        $seen = [];

        $makeEtm = function (array &$seen): EntityTypeManagerInterface {
            $query = $this->createMock(QueryInterface::class);
            $query->method('accessCheck')->willReturnCallback(
                function (bool $access) use (&$seen, &$query): QueryInterface {
                    $seen[] = $access;

                    return $query;
                }
            );
            $query->method('condition')->willReturnSelf();
            $query->method('sort')->willReturnSelf();
            $query->method('range')->willReturnSelf();
            $query->method('count')->willReturnSelf();
            $query->method('execute')->willReturn([]);

            $storage = $this->createMock(EntityStorageInterface::class);
            $storage->method('getQuery')->willReturn($query);
            $storage->method('loadMultiple')->willReturn([]);

            $etm = $this->createMock(EntityTypeManagerInterface::class);
            $etm->method('getStorage')->willReturn($storage);

            return $etm;
        };

        (new DrupalBlockTool($makeEtm($seen), null))->execute([]);
        (new DrupalViewsTool($this->alwaysInstalled(), $makeEtm($seen)))->execute([]);
        (new DrupalEntityTool($makeEtm($seen), $this->alwaysInstalled()))->execute(['entity_type' => 'node']);

        self::assertNotSame([], $seen, 'no tool called accessCheck() at all, so this invariant scanned nothing');
        self::assertNotContains(
            false,
            $seen,
            'accessCheck(false) returns rows the caller may not read',
        );
    }

    public function test_no_tool_constructor_accepts_a_console_flag(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->toolFiles() as $file) {
            $class = 'PhpClaw\\Drupal\\Tools\\'.basename((string) $file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $constructor = (new \ReflectionClass($class))->getConstructor();

            if ($constructor === null) {
                continue;
            }

            $checked++;

            foreach ($constructor->getParameters() as $parameter) {
                if (in_array(strtolower($parameter->getName()), ['isconsole', 'console'], true)) {
                    $offenders[] = basename((string) $file, '.php').'::__construct($'.$parameter->getName().')';
                }
            }
        }

        self::assertGreaterThan(
            0,
            $checked,
            'no tool constructor was reflected, so this invariant scanned nothing',
        );
        self::assertSame(
            [],
            $offenders,
            'the console answer comes from the process marker, never from a constructor argument. '
            .'A tool that takes one can be built into console mode by any caller: '
            .implode(', ', $offenders),
        );
    }

    public function test_a_permitted_caller_reaches_every_converted_tool(): void
    {
        $refused = [];

        foreach ($this->convertedTools() as $class => $factory) {
            if ($this->isForbidden($factory()->execute(['schema' => true]))) {
                $refused[] = $class;
            }
        }

        self::assertGreaterThan(
            0,
            count($this->convertedTools()),
            'no converted tool was checked, so this invariant scanned nothing',
        );
        self::assertSame(
            [],
            $refused,
            'a caller holding the permission was refused by: '.implode(', ', $refused),
        );
    }

    public function test_an_unpermitted_caller_is_refused_by_every_converted_tool(): void
    {
        $this->bootDrupalContainerWithUser(2, allPermissions: false);

        $allowed = [];

        foreach ($this->convertedTools() as $class => $factory) {
            if (! $this->isForbidden($factory()->execute(['schema' => true]))) {
                $allowed[] = $class;
            }
        }

        self::assertGreaterThan(
            0,
            count($this->convertedTools()),
            'no converted tool was checked, so this invariant scanned nothing',
        );
        self::assertSame(
            [],
            $allowed,
            'the permission guard let an unpermitted caller through. Offenders: '.implode(', ', $allowed),
        );
    }

    private function convertedTools(): array
    {
        return [
            'DrupalPathAliasTool' => fn (): DrupalPathAliasTool => new DrupalPathAliasTool(
                $this->createStub(Connection::class),
            ),
            'DrupalBlockTool' => fn (): DrupalBlockTool => new DrupalBlockTool(
                $this->createStub(EntityTypeManagerInterface::class),
                null,
            ),
            'DrupalMenuTool' => fn (): DrupalMenuTool => new DrupalMenuTool(
                $this->createStub(Connection::class),
                $this->createStub(EntityTypeManagerInterface::class),
                null,
            ),
            'DrupalContentModerationTool' => fn (): DrupalContentModerationTool => new DrupalContentModerationTool(
                $this->createStub(Connection::class),
                $this->alwaysInstalled(),
            ),
            'DrupalMediaTool' => fn (): DrupalMediaTool => new DrupalMediaTool(
                $this->createStub(Connection::class),
                $this->alwaysInstalled(),
            ),
            'DrupalModuleTool' => fn (): DrupalModuleTool => new DrupalModuleTool(
                $this->alwaysInstalled(),
                $this->createStub(ModuleExtensionList::class),
            ),
            'DrupalViewsTool' => fn (): DrupalViewsTool => new DrupalViewsTool(
                $this->alwaysInstalled(),
                $this->createStub(EntityTypeManagerInterface::class),
            ),
            'DrupalUserRoleTool' => fn (): DrupalUserRoleTool => new DrupalUserRoleTool(
                $this->createStub(Connection::class),
                $this->createStub(EntityTypeManagerInterface::class),
            ),
        ];
    }

    private function isForbidden(string $json): bool
    {
        $result = json_decode($json, true);

        return ($result['error']['code'] ?? null) === 'FORBIDDEN';
    }

    public function test_every_collection_tool_tests_a_full_last_page(): void
    {
        $missing = [];
        $checked = 0;

        foreach ($this->collectionTools() as $tool) {
            $file = __DIR__.'/'.$tool.'Test.php';

            if (! is_file($file)) {
                $missing[] = $tool.' has no test file at all';

                continue;
            }

            $checked++;
            $source = (string) file_get_contents($file);
            $found = false;

            foreach (preg_split('/(?=public function test)/', $source) ?: [] as $method) {
                $saysNoMore = preg_match('/assertFalse\([^;]*has_more/s', $method) === 1;
                $saysNoNext = preg_match('/assertNull\([^;]*next_offset/s', $method) === 1;

                if ($saysNoMore && $saysNoNext) {
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                $missing[] = $tool.' has no test asserting has_more false with next_offset null, '
                    .'so a page exactly the size of the limit is untested';
            }
        }

        self::assertGreaterThan(0, $checked, 'no collection tool was scanned, so this proves nothing');
        self::assertSame([], $missing, implode("\n", $missing));
    }

    public function test_every_converted_collection_tool_is_in_the_sort_map(): void
    {
        $missing = [];
        $checked = 0;
        $pending = $this->pendingConversion();
        $mapped = $this->collectionTools();

        foreach ($this->toolFiles() as $file) {
            $name = basename($file, '.php');

            if (in_array($name, $pending, true)) {
                continue;
            }

            if (! $this->declaresPagination($name)) {
                continue;
            }

            $checked++;

            if (! in_array($name, $mapped, true)) {
                $missing[] = $name.' paginates a collection but is not in collectionTools(), so the '
                    .'stable-sort and full-last-page checks skip it. An absent entry is not a pass, '
                    .'it is an absent check';
            }
        }

        self::assertGreaterThan(
            0,
            $checked,
            'no converted tool paginates a collection, so this invariant scanned nothing',
        );
        self::assertSame([], $missing, implode("\n", $missing));
    }

    private function declaresPagination(string $tool): bool
    {
        $class = 'PhpClaw\\Drupal\\Tools\\'.$tool;

        if (! class_exists($class) || ! method_exists($class, 'inputSchema')) {
            return false;
        }

        $schema = (new \ReflectionClass($class))->newInstanceWithoutConstructor()->inputSchema();
        $properties = array_keys((array) ($schema['properties'] ?? []));

        return in_array('limit', $properties, true) && in_array('offset', $properties, true);
    }

    private function alwaysInstalled(): ModuleHandlerInterface
    {
        $handler = $this->createStub(ModuleHandlerInterface::class);
        $handler->method('moduleExists')->willReturn(true);

        return $handler;
    }

    protected function storeBackingTables(): array
    {
        return [
            'DrupalConfigTool' => ['config'],
            'DrupalEntityTool' => ['node_field_data', 'taxonomy_term_field_data', 'users_field_data'],
            'DrupalWebformTool' => ['webform_submission', 'webform_submission_data'],
            'LogTool' => ['watchdog'],
            'DrupalMediaTool' => ['file_managed'],
            'DrupalMenuTool' => [],
            'DrupalBlockTool' => [],
            'DrupalViewsTool' => [],
            'DrupalCronTool' => [],
            'DrupalCacheTool' => [],
            'DrupalModuleTool' => [],
            'DrupalUserRoleTool' => [],
            'DrupalPathAliasTool' => [],
            'DrupalContentModerationTool' => [],
            'DatabaseTool' => [],
        ];
    }

    public function test_every_tool_with_a_backing_store_has_its_table_blocked(): void
    {
        $blockedTables = (array) (new \ReflectionClass(DatabaseTool::class))
            ->getReflectionConstant('BLOCKED_TABLES')
            ->getValue();

        self::assertNotSame([], $blockedTables, 'BLOCKED_TABLES could not be read from DatabaseTool');

        $map = $this->storeBackingTables();
        $offenders = [];
        $withStores = 0;

        foreach ($this->toolFiles() as $file) {
            $name = basename($file, '.php');

            if (! array_key_exists($name, $map)) {
                $offenders[] = $name.' is not in storeBackingTables(), so nothing says whether raw '
                    .'SQL can reach what it owns. Add it with its table, or with an empty list if '
                    .'it owns no store';

                continue;
            }

            if ($map[$name] === []) {
                continue;
            }

            $withStores++;

            foreach ($map[$name] as $table) {
                if (! in_array($table, $blockedTables, true)) {
                    $offenders[] = $name.' owns the "'.$table.'" store, but that table is missing '
                        .'from DatabaseTool::BLOCKED_TABLES, so raw SQL returns what '.$name
                        .' controls';
                }
            }
        }

        self::assertGreaterThan(
            1,
            $withStores,
            'the scan matched at most one tool with a backing store, so it could not have caught a regression',
        );
        self::assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_every_mapped_store_owner_is_a_real_tool(): void
    {
        $names = array_map(static fn (string $f): string => basename($f, '.php'), $this->toolFiles());

        foreach (array_keys($this->storeBackingTables()) as $tool) {
            self::assertContains($tool, $names, $tool.' is mapped to a backing table but no longer exists');
        }
    }

    private function phpSortProblem(string $tool): ?string
    {
        $order = $this->phpSortOrder($tool);

        if ($order === null) {
            return null;
        }

        [$got, $wantFirst, $field] = $order;

        if ($got === []) {
            return 'the tool returned no rows, so the tie-break could not be exercised';
        }

        return $got[0] === $wantFirst
            ? null
            : 'no deterministic tie-break in the PHP-side sort: expected "'.$wantFirst
                .'" first on '.$field.', got "'.$got[0].'"';
    }

    private function phpSortOrder(string $tool): ?array
    {
        return match ($tool) {
            'DrupalCronTool' => $this->cronSortOrder(),
            'DrupalCacheTool' => $this->cacheSortOrder(),
            'DrupalModuleTool' => $this->moduleSortOrder(),
            'DrupalBlockTool', 'DrupalViewsTool', 'DrupalUserRoleTool', 'DrupalEntityTool' => $this->entitySortOrder($tool),
            default => null,
        };
    }

    private function decodeRows(string $json, string $key): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $rows = [];

        foreach ((array) ($decoded['data'] ?? []) as $candidate) {
            if (is_array($candidate) && $candidate !== [] && is_array($candidate[0] ?? null)) {
                $rows = $candidate;

                break;
            }
        }

        return array_values(array_filter(array_map(
            static fn (mixed $row): mixed => is_array($row) ? ($row[$key] ?? null) : null,
            $rows,
        ), static fn (mixed $v): bool => $v !== null));
    }

    private function cronSortOrder(): array
    {
        $state = $this->createMock(StateInterface::class);
        $state->method('get')->willReturn(time());

        $time = $this->createMock(TimeInterface::class);
        $time->method('getCurrentTime')->willReturn(time());

        $queue = $this->createMock(QueueInterface::class);
        $queue->method('numberOfItems')->willReturn(5);

        $factory = $this->createMock(QueueFactory::class);
        $factory->method('get')->willReturn($queue);

        $workers = $this->createMock(QueueWorkerManagerInterface::class);
        $workers->method('getDefinitions')->willReturn([
            'zzz_worker' => ['title' => 'Zzz'],
            'aaa_worker' => ['title' => 'Aaa'],
        ]);

        $json = (new DrupalCronTool($state, $time, $factory, $workers))->execute([]);

        return [$this->decodeRows($json, 'name'), 'aaa_worker', 'name'];
    }

    private function cacheSortOrder(): array
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(7);

        $select = $this->createMock(Select::class);
        $select->method('countQuery')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);

        $schema = $this->createMock(Schema::class);
        $schema->method('findTables')->willReturn(['cache_zzz' => 'cache_zzz', 'cache_aaa' => 'cache_aaa']);
        $schema->method('tableExists')->willReturn(true);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($select);
        $db->method('schema')->willReturn($schema);

        return [$this->decodeRows((new DrupalCacheTool($db))->execute([]), 'bin'), 'cache_aaa', 'bin'];
    }

    private function moduleSortOrder(): array
    {
        $handler = $this->createMock(ModuleHandlerInterface::class);
        $handler->method('moduleExists')->willReturn(true);
        $handler->method('getModuleList')->willReturn(['zzz_mod' => null, 'aaa_mod' => null]);

        $list = $this->createMock(ModuleExtensionList::class);
        $list->method('getAllInstalledInfo')->willReturn([
            'zzz_mod' => ['name' => 'Same', 'version' => '1', 'package' => 'p'],
            'aaa_mod' => ['name' => 'Same', 'version' => '1', 'package' => 'p'],
        ]);

        return [
            $this->decodeRows((new DrupalModuleTool($handler, $list))->execute([]), 'machine_name'),
            'aaa_mod',
            'machine_name',
        ];
    }

    private function countingDb(): Connection
    {
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(0);
        $stmt->method('fetchAll')->willReturn([]);
        $stmt->method('fetchAssoc')->willReturn(false);

        $select = $this->createMock(Select::class);
        foreach (['fields', 'condition', 'range', 'groupBy', 'addExpression', 'orderBy', 'countQuery'] as $m) {
            $select->method($m)->willReturnSelf();
        }
        $select->method('execute')->willReturn($stmt);

        $db = $this->createMock(Connection::class);
        $db->method('select')->willReturn($select);

        return $db;
    }

    private function entitySortOrder(string $tool): ?array
    {
        $sorted = [];

        $query = $this->createMock(QueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('count')->willReturnSelf();
        $query->method('execute')->willReturn([]);
        $query->method('sort')->willReturnCallback(
            function (string $field, string $direction = 'ASC') use (&$sorted, &$query): QueryInterface {
                $sorted[] = $field;

                return $query;
            }
        );

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn($query);
        $storage->method('loadMultiple')->willReturn([]);

        $etm = $this->createMock(EntityTypeManagerInterface::class);
        $etm->method('getStorage')->willReturn($storage);

        $handler = $this->alwaysInstalled();

        match ($tool) {
            'DrupalEntityTool' => (new DrupalEntityTool($etm, $handler))->execute(['entity_type' => 'node']),
            'DrupalBlockTool' => (new DrupalBlockTool($etm, null))->execute([]),
            'DrupalViewsTool' => (new DrupalViewsTool($handler, $etm))->execute([]),
            'DrupalUserRoleTool' => (new DrupalUserRoleTool($this->countingDb(), $etm))->execute([]),
            default => null,
        };

        if ($tool === 'DrupalEntityTool') {
            return $sorted === []
                ? [[], 'a sort() call', 'entity query']
                : [[$sorted[0]], $sorted[0], 'entity query sort'];
        }

        return null;
    }
}
