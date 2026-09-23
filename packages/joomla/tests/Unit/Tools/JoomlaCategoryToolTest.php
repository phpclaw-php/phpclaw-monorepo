<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Joomla\Component\Administrator\Tools\JoomlaCategoryTool;
use PhpClaw\Joomla\Tests\Support\MockDatabase;
use PhpClaw\Joomla\Tests\Support\StubsJoomlaAccess;
use PHPUnit\Framework\TestCase;

final class JoomlaCategoryToolTest extends TestCase
{
    use StubsJoomlaAccess;

    protected function setUp(): void
    {
        parent::setUp();
        $this->grantJoomlaAccess();
    }

    protected function tearDown(): void
    {
        $this->clearJoomlaAccess();
        parent::tearDown();
    }

    public function test_it_refuses_when_the_identity_lacks_the_action(): void
    {
        $this->denyJoomlaAccess();

        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);

        $this->assertForbiddenEnvelope((new JoomlaCategoryTool($db))->execute([]));
        self::assertSame(
            '',
            (string) MockDatabase::lastQuery($db),
            'authorisation must be refused before any query runs',
        );
    }

    public function test_it_refuses_when_there_is_no_identity_at_all(): void
    {
        $this->clearJoomlaAccess();

        $this->assertForbiddenEnvelope((new JoomlaCategoryTool(MockDatabase::raw($this)))->execute([]));
    }

    public function test_it_requires_the_chat_action_on_com_phpclaw(): void
    {
        self::assertSame('phpclaw.chat.use', (new JoomlaCategoryTool(MockDatabase::raw($this)))->requiredCapability());
    }

    public function test_the_granted_identity_is_the_positive_control(): void
    {
        $this->grantJoomlaAccess();

        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '1']]);
        MockDatabase::enqueueAssocList($db, [['id' => 2, 'title' => 'News']]);

        $result = json_decode((new JoomlaCategoryTool($db))->execute([]), true);

        self::assertTrue(
            $result['success'],
            'the same call refused under denyJoomlaAccess must succeed here, or the refusal proves nothing',
        );
        self::assertNotSame('', (string) MockDatabase::lastQuery($db));
    }

    public function test_name(): void
    {
        $this->assertSame('joomla_categories', (new JoomlaCategoryTool(MockDatabase::raw($this)))->name());
    }

    public function test_description(): void
    {
        $desc = (new JoomlaCategoryTool(MockDatabase::raw($this)))->description();
        $this->assertStringContainsString('categories', $desc);
        $this->assertStringContainsString('hierarchy', $desc);
    }

    public function test_input_schema(): void
    {
        $schema = (new JoomlaCategoryTool(MockDatabase::raw($this)))->inputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('columns', $schema['properties']);
        $this->assertArrayHasKey('search', $schema['properties']);
        $this->assertArrayHasKey('extension', $schema['properties']);
        $this->assertArrayHasKey('parent_id', $schema['properties']);
        $this->assertArrayHasKey('level', $schema['properties']);
        $this->assertArrayHasKey('aggregate', $schema['properties']);
        $this->assertArrayHasKey('schema', $schema['properties']);
    }

    public function test_schema_mode(): void
    {
        $tool = new JoomlaCategoryTool(MockDatabase::raw($this));
        $result = json_decode($tool->execute(['schema' => true]), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('schema', $result['meta']['mode']);
        $this->assertIsArray($result['data']['available_columns']);
        $this->assertContains('id', $result['data']['available_columns']);
        $this->assertContains('title', $result['data']['available_columns']);
        $this->assertContains('parent_id', $result['data']['available_columns']);
        $this->assertIsArray($result['data']['blocked_columns']);
        $this->assertContains('params', $result['data']['blocked_columns']);
        $this->assertArrayHasKey('hierarchy_info', $result['data']);
    }

    public function test_aggregate_mode(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [
            ['total' => '5', 'published' => '4', 'unpublished' => '1', 'extension' => 'com_content', 'level' => '1'],
        ]);

        $result = json_decode((new JoomlaCategoryTool($db))->execute(['aggregate' => true]), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('aggregate', $result['meta']['mode']);
        $this->assertSame(5, $result['data']['total']);
        $this->assertIsArray($result['data']['by_extension']);
    }

    public function test_execute_happy_path(): void
    {
        $db = MockDatabase::raw($this);

        MockDatabase::enqueueAssocList($db, [['total' => '1']]);
        MockDatabase::enqueueAssocList($db, [
            ['id' => 2, 'title' => 'News', 'alias' => 'news', 'parent_id' => 1, 'level' => 1, 'published' => 1, 'extension' => 'com_content', 'language' => '*'],
        ]);

        $result = json_decode((new JoomlaCategoryTool($db))->execute([]), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $result['meta']['count']);
        $this->assertSame(1, $result['meta']['total']);
        $this->assertFalse($result['meta']['has_more']);
        $this->assertNull($result['meta']['next_offset']);
        $this->assertSame('News', $result['data']['categories'][0]['title']);
        $this->assertIsArray($result['meta']['columns_returned']);
        $this->assertArrayHasKey('limit', $result['meta']);
    }

    public function test_build_where_excludes_root_node(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        (new JoomlaCategoryTool($db))->execute([]);

        $this->assertStringContainsString('cat.id > 1', MockDatabase::lastQuery($db));
    }

    public function test_with_article_count_emits_left_join(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        (new JoomlaCategoryTool($db))->execute(['with_article_count' => true]);

        $sql = MockDatabase::lastQuery($db);
        $this->assertStringContainsString('LEFT JOIN', $sql);
        $this->assertStringContainsString('art.catid = cat.id', $sql);
        $this->assertStringContainsString('art.state = 1', $sql);
        $this->assertStringContainsString('GROUP BY cat.id', $sql);
        $this->assertStringContainsString('COUNT(art.id) AS article_count', $sql);
    }

    public function test_filters_substitute_bindings_into_sql(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        (new JoomlaCategoryTool($db))->execute([
            'search' => 'News',
            'extension' => 'com_content',
            'language' => 'en-GB',
            'parent_id' => 5,
            'level' => 2,
            'published' => 1,
        ]);

        $sql = MockDatabase::lastQuery($db);
        $this->assertStringNotContainsString(':search', $sql);
        $this->assertStringNotContainsString(':ext', $sql);
        $this->assertStringNotContainsString(':parent', $sql);
        $this->assertStringContainsString("'%News%'", $sql);
        $this->assertStringContainsString("'com_content'", $sql);
        $this->assertStringContainsString("cat.parent_id = '5'", $sql);
        $this->assertStringContainsString("cat.level = '2'", $sql);
    }

    public function test_all_columns_marker_expands_to_available_minus_blocked(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        (new JoomlaCategoryTool($db))->execute(['columns' => ['*']]);

        $sql = MockDatabase::lastQuery($db);
        foreach (['cat.description', 'cat.path', 'cat.access'] as $col) {
            $this->assertStringContainsString($col === 'cat.description' ? 'SUBSTRING(cat.description' : $col, $sql);
        }
        $this->assertStringNotContainsString('cat.params', $sql);
        $this->assertStringNotContainsString('cat.metadata', $sql);
    }

    public function test_columns_string_input_is_normalised(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        (new JoomlaCategoryTool($db))->execute(['columns' => 'id, title, alias']);

        $sql = MockDatabase::lastQuery($db);
        $this->assertStringContainsString('cat.id', $sql);
        $this->assertStringContainsString('cat.title', $sql);
        $this->assertStringContainsString('cat.alias', $sql);
    }

    public function test_columns_json_string_input_is_normalised(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        (new JoomlaCategoryTool($db))->execute(['columns' => '["id","title","alias"]']);

        $sql = MockDatabase::lastQuery($db);
        $this->assertStringContainsString('cat.id', $sql);
        $this->assertStringContainsString('cat.title', $sql);
        $this->assertStringContainsString('cat.alias', $sql);
    }

    public function test_order_by_and_order_dir_desc(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        (new JoomlaCategoryTool($db))->execute([
            'order_by' => 'title',
            'order_dir' => 'DESC',
        ]);

        $this->assertStringContainsString('ORDER BY cat.title DESC', MockDatabase::lastQuery($db));
    }

    public function test_unknown_order_by_falls_back_to_default(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        (new JoomlaCategoryTool($db))->execute(['order_by' => 'evil; DROP TABLE x']);

        $this->assertStringContainsString('ORDER BY cat.lft', MockDatabase::lastQuery($db));
    }

    public function test_limit_above_the_maximum_is_rejected_not_capped(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        $result = json_decode((new JoomlaCategoryTool($db))->execute(['limit' => 9999]), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_LIMIT', $result['error']['code']);
        self::assertSame(
            '',
            (string) MockDatabase::lastQuery($db),
            'an out-of-range limit must be refused before any query runs',
        );
    }

    public function test_db_exception_is_wrapped_in_tool_exception(): void
    {
        $db = MockDatabase::raw($this);
        $db->method('loadAssocList')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(ToolException::class);
        $this->expectExceptionMessage('JoomlaCategoryTool: query failed.');

        (new JoomlaCategoryTool($db))->execute([]);
    }

    public function test_blocked_columns_are_refused_not_silently_dropped(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        $result = json_decode(
            (new JoomlaCategoryTool($db))->execute(['columns' => ['id', 'params', 'title']]),
            true,
        );

        self::assertFalse($result['success']);
        self::assertSame('BLOCKED_COLUMN', $result['error']['code']);
        self::assertContains('params', $result['error']['blocked_columns']);
        self::assertSame(
            '',
            (string) MockDatabase::lastQuery($db),
            'a blocked column must be refused before any query runs',
        );
    }

    public function test_resolve_columns_prepends_id_when_missing(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        (new JoomlaCategoryTool($db))->execute(['columns' => ['title', 'alias']]);

        $sql = MockDatabase::lastQuery($db);
        $position_id = strpos($sql, 'cat.id');
        $position_title = strpos($sql, 'cat.title');
        $this->assertNotFalse($position_id);
        $this->assertNotFalse($position_title);
        $this->assertLessThan($position_title, $position_id);
    }

    public function test_columns_of_a_wrong_type_are_refused(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        $result = json_decode((new JoomlaCategoryTool($db))->execute(['columns' => 42]), true);

        self::assertFalse($result['success']);
        self::assertSame('INVALID_COLUMNS', $result['error']['code']);
    }

    public function test_only_blocked_columns_are_refused(): void
    {
        $db = MockDatabase::raw($this);
        MockDatabase::enqueueAssocList($db, [['total' => '0']]);
        MockDatabase::enqueueAssocList($db, []);

        $result = json_decode(
            (new JoomlaCategoryTool($db))->execute(['columns' => ['params', 'metadata']]),
            true,
        );

        self::assertFalse($result['success']);
        self::assertSame('BLOCKED_COLUMN', $result['error']['code']);
    }
}
