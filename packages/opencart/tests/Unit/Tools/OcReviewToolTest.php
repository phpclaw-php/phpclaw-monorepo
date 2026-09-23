<?php

declare(strict_types=1);

namespace PhpClaw\OpenCart\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\OpenCart\Tests\Helpers\OcDbTestCase;
use PhpClaw\OpenCart\Tools\OcReviewTool;

final class OcReviewToolTest extends OcDbTestCase
{
    private OcReviewTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $reviewTable = $this->prefix.'review';
        $prodDescTable = $this->prefix.'product_description';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$reviewTable}` (
                review_id     INT      NOT NULL AUTO_INCREMENT,
                product_id    INT      NOT NULL DEFAULT 0,
                customer_id   INT      NOT NULL DEFAULT 0,
                author        VARCHAR(64)  NOT NULL DEFAULT '',
                text          TEXT     NOT NULL,
                rating        TINYINT  NOT NULL DEFAULT 1,
                status        TINYINT  NOT NULL DEFAULT 0,
                date_added    DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_modified DATETIME NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (review_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$prodDescTable}` (
                product_id  INT         NOT NULL DEFAULT 0,
                language_id INT         NOT NULL DEFAULT 1,
                name        VARCHAR(255) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$reviewTable}` (review_id,product_id,customer_id,author,text,rating,status,date_added,date_modified) VALUES (1,10,1,'Alice','Great product',5,1,'2024-04-01 10:00:00','2024-04-01')");
        $this->seed("INSERT INTO `{$reviewTable}` (review_id,product_id,customer_id,author,text,rating,status,date_added,date_modified) VALUES (2,10,2,'Bob','It broke quickly',1,0,'2024-05-01 10:00:00','2024-05-01')");
        $this->seed("INSERT INTO `{$reviewTable}` (review_id,product_id,customer_id,author,text,rating,status,date_added,date_modified) VALUES (3,20,3,'Carol','Average quality',3,1,'2024-06-01 10:00:00','2024-06-01')");
        $this->seed("INSERT INTO `{$prodDescTable}` (product_id,language_id,name) VALUES (10,1,'Widget A')");
        $this->seed("INSERT INTO `{$prodDescTable}` (product_id,language_id,name) VALUES (20,1,'Widget B')");

        $this->tool = new OcReviewTool($this->db, $this->prefix, true);
    }

    private function payload(string $json): array
    {
        $d = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);

        return $d['data'];
    }

    public function test_name(): void
    {
        self::assertSame('oc_review', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'QUERY OpenCart product reviews',
            $this->tool->description(),
        );
    }

    public function test_list_returns_all_reviews(): void
    {
        $payload = $this->payload($this->tool->execute(['mode' => 'list']));
        self::assertCount(3, $payload['reviews']);
    }

    public function test_default_mode_is_list(): void
    {
        $payload = $this->payload($this->tool->execute([]));
        self::assertArrayHasKey('reviews', $payload);
    }

    public function test_schema_mode_returns_metadata(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'schema']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('schema', $d['meta']['mode']);
        self::assertArrayHasKey('available_columns', $d['data']);
        self::assertArrayHasKey('filters', $d['data']);
    }

    public function test_schema_mode_works_without_pdo(): void
    {
        $tool = new OcReviewTool(null, $this->prefix, true);
        $d = json_decode($tool->execute(['mode' => 'schema']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('schema', $d['meta']['mode']);
    }

    public function test_throws_when_pdo_null_on_list(): void
    {
        $tool = new OcReviewTool(null, $this->prefix, true);
        $this->expectException(ToolException::class);
        $tool->execute(['mode' => 'list']);
    }

    public function test_aggregate_mode_total_reviews(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'aggregate']), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($d['success']);
        self::assertSame('aggregate', $d['meta']['mode']);
        self::assertSame(3, $d['data']['stats']['total_reviews']);
    }

    public function test_aggregate_avg_rating(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'aggregate']), true, 512, JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0.0, $d['data']['stats']['avg_rating']);
    }

    public function test_aggregate_pending_count(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'aggregate']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $d['data']['stats']['pending_count']);
    }

    public function test_aggregate_by_rating_keys(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'aggregate']), true, 512, JSON_THROW_ON_ERROR);
        $byRating = $d['data']['stats']['by_rating'];
        self::assertArrayHasKey('1', $byRating);
        self::assertArrayHasKey('5', $byRating);
        self::assertSame(1, $byRating['5']);
        self::assertSame(1, $byRating['1']);
    }

    public function test_filter_by_status_approved(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 1]));
        self::assertCount(2, $payload['reviews']);
    }

    public function test_filter_by_status_pending(): void
    {
        $payload = $this->payload($this->tool->execute(['status' => 0]));
        self::assertCount(1, $payload['reviews']);
        self::assertSame('Bob', $payload['reviews'][0]['author']);
    }

    public function test_filter_by_product_id(): void
    {
        $payload = $this->payload($this->tool->execute(['product_id' => 10]));
        self::assertCount(2, $payload['reviews']);
    }

    public function test_filter_by_rating(): void
    {
        $payload = $this->payload($this->tool->execute(['rating' => 5]));
        self::assertCount(1, $payload['reviews']);
        self::assertSame('Alice', $payload['reviews'][0]['author']);
    }

    public function test_search_by_author(): void
    {
        $payload = $this->payload($this->tool->execute(['search' => 'Carol']));
        self::assertCount(1, $payload['reviews']);
    }

    public function test_search_by_text(): void
    {
        $payload = $this->payload($this->tool->execute(['search' => 'broke']));
        self::assertCount(1, $payload['reviews']);
        self::assertSame('Bob', $payload['reviews'][0]['author']);
    }

    public function test_filter_by_date_after(): void
    {
        $payload = $this->payload($this->tool->execute(['date_after' => '2024-05-01']));
        self::assertCount(2, $payload['reviews']);
    }

    public function test_filter_by_date_before(): void
    {
        $payload = $this->payload($this->tool->execute(['date_before' => '2024-04-30']));
        self::assertCount(1, $payload['reviews']);
        self::assertSame('Alice', $payload['reviews'][0]['author']);
    }

    public function test_columns_wildcard_includes_text(): void
    {
        $payload = $this->payload($this->tool->execute(['columns' => ['*']]));
        self::assertContains('text', $payload['columns_returned']);
    }

    public function test_columns_empty_returns_defaults(): void
    {
        $payload = $this->payload($this->tool->execute(['columns' => []]));
        self::assertContains('author', $payload['columns_returned']);
        self::assertNotContains('text', $payload['columns_returned']);
    }

    public function test_limit_caps_results(): void
    {
        $payload = $this->payload($this->tool->execute(['limit' => 1]));
        self::assertCount(1, $payload['reviews']);
    }

    public function test_aggregate_with_product_id_filter(): void
    {
        $d = json_decode($this->tool->execute(['mode' => 'aggregate', 'product_id' => 10]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $d['data']['stats']['total_reviews']);
    }

    public function test_product_name_column_via_join(): void
    {
        $payload = $this->payload($this->tool->execute(['columns' => ['id', 'product_name', 'rating']]));
        $row = $payload['reviews'][0];
        self::assertArrayHasKey('product_name', $row);
    }

    public function test_forbidden_when_caller_may_not_use_module(): void
    {
        $tool = new OcReviewTool($this->db, $this->prefix, false);
        $d = json_decode($tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($d['success']);
        self::assertSame('FORBIDDEN', $d['error']['code']);
    }

    public function test_meta_total_matches_review_count(): void
    {
        $d = json_decode($this->tool->execute([]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($d['meta']['total'], count($d['data']['reviews']));
    }

    public function test_customer_id_never_returned(): void
    {
        $payload = $this->payload($this->tool->execute(['columns' => ['*']]));
        self::assertCount(3, $payload['reviews']);

        foreach ($payload['reviews'] as $row) {
            self::assertArrayNotHasKey('customer_id', $row);
        }
    }
}
