<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Tools;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\StatementInterface;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PhpClaw\Drupal\Tools\DrupalPathAliasTool;
use PHPUnit\Framework\TestCase;

final class DrupalPathAliasToolTest extends TestCase
{
    use BootsDrupalContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootDrupalContainerWithUser();
    }

    private function buildDb(array $mainRows, int $countValue): Connection
    {
        $mainStmt = $this->createMock(StatementInterface::class);
        $mainStmt->method('fetchAssoc')->willReturnOnConsecutiveCalls(...array_merge($mainRows, [false]));

        $mainSelect = $this->createMock(Select::class);
        $mainSelect->method('fields')->willReturnSelf();
        $mainSelect->method('condition')->willReturnSelf();
        $mainSelect->method('orderBy')->willReturnSelf();
        $mainSelect->method('range')->willReturnSelf();
        $mainSelect->method('execute')->willReturn($mainStmt);

        $countStmt = $this->createMock(StatementInterface::class);
        $countStmt->method('fetchField')->willReturn($countValue);

        $countSelect = $this->createMock(Select::class);
        $countSelect->method('countQuery')->willReturnSelf();
        $countSelect->method('execute')->willReturn($countStmt);

        $db = $this->createMock(Connection::class);
        $db->method('select')
            ->willReturnOnConsecutiveCalls($mainSelect, $countSelect);
        $db->method('escapeLike')->willReturnCallback(static fn (string $s) => $s);

        return $db;
    }

    public function test_name_returns_correct_value(): void
    {
        $tool = new DrupalPathAliasTool($this->createMock(Connection::class));
        $this->assertSame('drupal_path_aliases', $tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $tool = new DrupalPathAliasTool($this->createMock(Connection::class));

        self::assertStringContainsString(
            'Query Drupal URL path aliases',
            $tool->description(),
        );
    }

    public function test_input_schema_has_expected_properties(): void
    {
        $tool = new DrupalPathAliasTool($this->createMock(Connection::class));
        $schema = $tool->inputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertArrayHasKey('search', $schema['properties']);
        $this->assertArrayHasKey('limit', $schema['properties']);
        $this->assertArrayHasKey('offset', $schema['properties']);
        $this->assertArrayHasKey('schema', $schema['properties']);
        $this->assertSame([], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function test_limit_property_has_max_50(): void
    {
        $tool = new DrupalPathAliasTool($this->createMock(Connection::class));
        $schema = $tool->inputSchema();

        $this->assertSame(50, $schema['properties']['limit']['maximum']);
    }

    public function test_execute_returns_json_with_expected_keys(): void
    {
        $rows = [
            [
                'id' => 1,
                'path' => '/node/42',
                'alias' => '/about-us',
                'langcode' => 'en',
                'status' => 1,
            ],
        ];

        $db = $this->buildDb($rows, 15);
        $tool = new DrupalPathAliasTool($db);

        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['success']);
        $this->assertSame('query', $decoded['meta']['mode']);
        $this->assertCount(1, $decoded['data']['aliases']);
        $this->assertSame(15, $decoded['meta']['total']);
        $this->assertSame(1, $decoded['meta']['count']);
        $this->assertTrue($decoded['meta']['has_more']);
        $this->assertSame(1, $decoded['meta']['next_offset']);
    }

    public function test_execute_alias_has_expected_structure(): void
    {
        $rows = [
            [
                'id' => 7,
                'path' => '/node/10',
                'alias' => '/contact',
                'langcode' => 'fr',
                'status' => 0,
            ],
        ];

        $db = $this->buildDb($rows, 1);
        $tool = new DrupalPathAliasTool($db);

        $result = $tool->execute([]);
        $decoded = json_decode($result, true);
        $alias = $decoded['data']['aliases'][0];

        $this->assertSame(7, $alias['id']);
        $this->assertSame('/node/10', $alias['path']);
        $this->assertSame('/contact', $alias['alias']);
        $this->assertSame('fr', $alias['language']);
        $this->assertSame('inactive', $alias['status']);
    }

    public function test_execute_empty_result(): void
    {
        $db = $this->buildDb([], 0);
        $tool = new DrupalPathAliasTool($db);

        $result = $tool->execute([]);
        $decoded = json_decode($result, true);

        $this->assertSame([], $decoded['data']['aliases']);
        $this->assertSame(0, $decoded['meta']['count']);
        $this->assertSame(0, $decoded['meta']['total']);
    }

    public function test_execute_with_path_filter(): void
    {
        $rows = [
            [
                'id' => 3,
                'path' => '/node/42',
                'alias' => '/about',
                'langcode' => 'en',
                'status' => 1,
            ],
        ];

        $db = $this->buildDb($rows, 5);
        $tool = new DrupalPathAliasTool($db);

        $result = $tool->execute(['path' => '/node/42']);
        $decoded = json_decode($result, true);

        $this->assertCount(1, $decoded['data']['aliases']);
        $this->assertSame('/node/42', $decoded['data']['aliases'][0]['path']);
    }

    public function test_execute_with_search_filter(): void
    {
        $db = $this->buildDb([], 10);
        $tool = new DrupalPathAliasTool($db);

        $result = $tool->execute(['search' => 'blog']);
        $decoded = json_decode($result, true);

        $this->assertTrue($decoded['success']);
        $this->assertArrayHasKey('aliases', $decoded['data']);
    }

    public function test_forbidden_when_the_caller_lacks_the_permission(): void
    {
        $this->bootDrupalContainerWithUser(2, allPermissions: false);

        $tool = new DrupalPathAliasTool($this->createMock(Connection::class));

        $decoded = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($decoded['success']);
        self::assertSame('FORBIDDEN', $decoded['error']['code']);
        self::assertNull($decoded['data']);
        self::assertSame('error', $decoded['meta']['mode']);
        self::assertStringContainsString('use phpclaw chat', $decoded['error']['message']);
    }

    public function test_the_required_permission_is_the_single_shipped_one(): void
    {
        $tool = new DrupalPathAliasTool($this->createMock(Connection::class));

        self::assertSame('use phpclaw chat', $tool->requiredCapability());
    }

    public function test_schema_mode_runs_no_query_and_surfaces_examples(): void
    {
        $tool = new DrupalPathAliasTool($this->createMock(Connection::class));

        $decoded = json_decode($tool->execute(['schema' => true]), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('schema', $decoded['meta']['mode']);
        self::assertFalse($decoded['meta']['database_query_performed']);
        self::assertSame(['alias'], $decoded['data']['untrusted_columns']);
        self::assertSame('use phpclaw chat', $decoded['data']['drupal_permission']);
        self::assertCount(3, $decoded['data']['examples']);
    }

    public function test_untrusted_warning_fires_only_when_rows_come_back(): void
    {
        $rows = [['id' => 1, 'path' => '/node/1', 'alias' => '/IGNORE-ALL-PREVIOUS', 'langcode' => 'en', 'status' => 1]];

        $withRows = json_decode(
            (new DrupalPathAliasTool($this->buildDb($rows, 1)))->execute([]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $empty = json_decode(
            (new DrupalPathAliasTool($this->buildDb([], 0)))->execute([]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('UNTRUSTED_CONTENT', $withRows['warnings'][0]['code']);
        self::assertSame(['alias'], $withRows['meta']['untrusted_fields_returned']);
        self::assertStringContainsString('create url aliases', $withRows['warnings'][0]['message']);

        self::assertSame([], $empty['warnings']);
        self::assertArrayNotHasKey('untrusted_fields_returned', $empty['meta']);
    }

    public function test_total_comes_from_a_count_not_from_the_page(): void
    {
        $rows = [['id' => 1, 'path' => '/a', 'alias' => '/a', 'langcode' => 'en', 'status' => 1]];

        $decoded = json_decode(
            (new DrupalPathAliasTool($this->buildDb($rows, 97)))->execute(['limit' => 1]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(97, $decoded['meta']['total']);
        self::assertSame(1, $decoded['meta']['count']);
        self::assertTrue($decoded['meta']['has_more']);
        self::assertSame(1, $decoded['meta']['next_offset']);
    }

    public function test_a_full_last_page_does_not_claim_more(): void
    {
        $rows = [['id' => 1, 'path' => '/a', 'alias' => '/a', 'langcode' => 'en', 'status' => 1]];

        $decoded = json_decode(
            (new DrupalPathAliasTool($this->buildDb($rows, 1)))->execute(['limit' => 1]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertFalse($decoded['meta']['has_more']);
        self::assertNull($decoded['meta']['next_offset']);
    }

    public function test_unknown_argument_is_refused(): void
    {
        $decoded = json_decode(
            (new DrupalPathAliasTool($this->createMock(Connection::class)))->execute(['foo' => 1]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('UNKNOWN_ARGUMENT', $decoded['error']['code']);
    }

    public function test_unknown_order_column_is_refused(): void
    {
        $decoded = json_decode(
            (new DrupalPathAliasTool($this->createMock(Connection::class)))->execute(['order_by' => 'zzz']),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('UNKNOWN_COLUMN', $decoded['error']['code']);
    }

    public function test_limit_over_the_maximum_is_refused(): void
    {
        $decoded = json_decode(
            (new DrupalPathAliasTool($this->createMock(Connection::class)))->execute(['limit' => 9999]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('INVALID_LIMIT', $decoded['error']['code']);
    }
}
