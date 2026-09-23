<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsDocumentedColumnsAreEmitted;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsOrderTool;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(PsOrderTool::class)]
final class PsOrderToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsDocumentedColumnsAreEmitted;
    use AssertsToolEnvelope;

    private PsOrderTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $ordersTable = $this->prefix.'orders';
        $stateTable = $this->prefix.'order_state_lang';
        $customerTable = $this->prefix.'customer';
        $carrierTable = $this->prefix.'carrier';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$ordersTable}` (
                id_order            INT           NOT NULL AUTO_INCREMENT,
                id_customer         INT           NOT NULL DEFAULT 0,
                id_carrier          INT           NOT NULL DEFAULT 0,
                reference           VARCHAR(9)    NOT NULL DEFAULT '',
                total_paid          DECIMAL(20,6) NOT NULL DEFAULT 0,
                total_paid_tax_incl DECIMAL(20,6) NOT NULL DEFAULT 0,
                payment             VARCHAR(255)  NOT NULL DEFAULT '',
                current_state       INT           NOT NULL DEFAULT 0,
                `valid`             TINYINT(1)    NOT NULL DEFAULT 0,
                shipping_number     VARCHAR(64)   NOT NULL DEFAULT '',
                invoice_number      INT           NOT NULL DEFAULT 0,
                date_add            DATETIME      NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_upd            DATETIME      NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$stateTable}` (
                id_order_state INT         NOT NULL DEFAULT 0,
                id_lang        INT         NOT NULL DEFAULT 1,
                name           VARCHAR(64) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$customerTable}` (
                id_customer INT         NOT NULL AUTO_INCREMENT,
                firstname   VARCHAR(255) NOT NULL DEFAULT '',
                lastname    VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (id_customer)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$carrierTable}` (
                id_carrier INT         NOT NULL AUTO_INCREMENT,
                name       VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (id_carrier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS `{$this->prefix}order_carrier` (
                id_order_carrier INT NOT NULL AUTO_INCREMENT,
                id_order         INT NOT NULL DEFAULT 0,
                tracking_number  VARCHAR(64) NOT NULL DEFAULT '',
                PRIMARY KEY (id_order_carrier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$ordersTable}` (id_order,id_customer,id_carrier,reference,total_paid,total_paid_tax_incl,payment,current_state,`valid`,shipping_number,invoice_number,date_add,date_upd) VALUES (1,5,1,'ABCDE',100.00,120.00,'Credit card',2,1,'',0,'2024-01-15 10:00:00','2024-01-15 10:00:00')");
        $this->seed("INSERT INTO `{$ordersTable}` (id_order,id_customer,id_carrier,reference,total_paid,total_paid_tax_incl,payment,current_state,`valid`,shipping_number,invoice_number,date_add,date_upd) VALUES (2,6,1,'FGHIJ',50.00,55.00,'PayPal',3,0,'',0,'2024-01-16 11:00:00','2024-01-16 11:00:00')");
        $this->seed("INSERT INTO `{$stateTable}` (id_order_state,id_lang,name) VALUES (2,1,'Payment accepted')");
        $this->seed("INSERT INTO `{$stateTable}` (id_order_state,id_lang,name) VALUES (3,1,'Processing in progress')");
        $this->seed("INSERT INTO `{$customerTable}` (id_customer,firstname,lastname) VALUES (5,'Alice','Smith')");
        $this->seed("INSERT INTO `{$customerTable}` (id_customer,firstname,lastname) VALUES (6,'Bob','Jones')");
        $this->seed("INSERT INTO `{$carrierTable}` (id_carrier,name) VALUES (1,'DHL')");
        $this->seed("INSERT INTO `{$this->prefix}order_carrier` (id_order,tracking_number) VALUES (1,'TRK123')");

        $this->tool = new PsOrderTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_order(): void
    {
        self::assertSame('ps_order', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'PrestaShop orders with status and pay',
            $this->tool->description(),
        );
    }

    public function test_execute_returns_orders(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('orders', $data);
        self::assertCount(2, $data['orders']);
    }

    public function test_execute_respects_limit(): void
    {
        $result = $this->tool->execute(['limit' => 1]);
        $data = $this->flat($result);

        self::assertArrayHasKey('orders', $data);
        self::assertCount(1, $data['orders']);
    }

    public function test_execute_filters_by_customer(): void
    {
        $result = $this->tool->execute(['customer_id' => 5]);
        $data = $this->flat($result);

        self::assertArrayHasKey('orders', $data);
        self::assertCount(1, $data['orders']);
    }

    public function test_execute_throws_on_null_db(): void
    {
        $tool = new PsOrderTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsOrderTool(null, $this->prefix);
        $result = $tool->execute(['schema' => true]);

        self::assertJson($result);
        $data = $this->flat($result);
        self::assertSame('schema', $data['mode']);
    }

    public function test_aggregate_mode_returns_stats(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('stats', $data);
        self::assertArrayHasKey('total_orders', $data['stats']);
        self::assertArrayHasKey('total_revenue', $data['stats']);
        self::assertArrayHasKey('avg_order_value', $data['stats']);
        self::assertArrayHasKey('by_state', $data['stats']);
        self::assertSame(2, $data['stats']['total_orders']);
    }

    public function test_aggregate_filters_by_state(): void
    {
        $result = $this->tool->execute(['aggregate' => true, 'state_id' => 2]);
        $data = $this->flat($result);

        self::assertSame(1, $data['stats']['total_orders']);
    }

    public function test_filter_by_search_reference(): void
    {
        $result = $this->tool->execute(['search' => 'ABCDE']);
        $data = $this->flat($result);

        self::assertCount(1, $data['orders']);
    }

    public function test_filter_by_search_customer_name(): void
    {
        $result = $this->tool->execute(['search' => 'Alice']);
        $data = $this->flat($result);

        self::assertCount(1, $data['orders']);
    }

    public function test_filter_by_state_id(): void
    {
        $result = $this->tool->execute(['state_id' => 3]);
        $data = $this->flat($result);

        self::assertCount(1, $data['orders']);
    }

    public function test_filter_by_date_after(): void
    {
        $result = $this->tool->execute(['date_after' => '2024-01-16']);
        $data = $this->flat($result);

        self::assertCount(1, $data['orders']);
    }

    public function test_filter_by_date_before(): void
    {
        $result = $this->tool->execute(['date_before' => '2024-01-15']);
        $data = $this->flat($result);

        self::assertCount(1, $data['orders']);
    }

    public function test_filter_by_min_total(): void
    {
        $result = $this->tool->execute(['min_total' => 100]);
        $data = $this->flat($result);

        self::assertCount(1, $data['orders']);
    }

    public function test_filter_by_max_total(): void
    {
        $result = $this->tool->execute(['max_total' => 60]);
        $data = $this->flat($result);

        self::assertCount(1, $data['orders']);
    }

    public function test_columns_wildcard_returns_all_columns(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertArrayHasKey('columns_returned', $data);
        self::assertContains('payment', $data['columns_returned']);
        self::assertContains('carrier_name', $data['columns_returned']);
    }

    public function test_columns_specific_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['reference', 'total_paid_tax_incl']]);
        $data = $this->flat($result);

        self::assertArrayHasKey('id', $data['orders'][0]);
        self::assertArrayHasKey('reference', $data['orders'][0]);
        self::assertArrayHasKey('total_paid_tax_incl', $data['orders'][0]);
        self::assertArrayNotHasKey('payment', $data['orders'][0]);
    }

    public function test_columns_invalid_names_filtered_out(): void
    {
        $result = $this->tool->execute(['columns' => ['nonexistent', 'reference']]);
        $data = $this->flat($result);

        self::assertArrayHasKey('reference', $data['orders'][0]);
        self::assertArrayNotHasKey('nonexistent', $data['orders'][0]);
    }

    public function test_columns_non_array_falls_back_to_defaults(): void
    {
        $result = $this->tool->execute(['columns' => 'reference']);
        $data = $this->flat($result);

        self::assertContains('id', $data['columns_returned']);
    }

    public function test_empty_result_returns_empty_orders_array(): void
    {
        $result = $this->tool->execute(['state_id' => 999]);
        $data = $this->flat($result);

        self::assertSame([], $data['orders']);
        self::assertSame(0, $data['total']);
    }

    public function test_a_limit_beyond_the_maximum_is_refused_rather_than_silently_clamped(): void
    {
        $result = $this->tool->execute(['limit' => 9999]);

        self::assertSame('INVALID_LIMIT', $this->errorCode($result));
        self::assertStringContainsString('between 1 and 100', $this->envelope($result)['error']['message']);
    }

    public function test_the_highest_accepted_limit_still_answers(): void
    {
        self::assertArrayHasKey('orders', $this->flat($this->tool->execute(['limit' => 100])));
    }

    public function test_orders_have_id_field(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertSame([2, 1], array_column($data['orders'], 'id'));
    }

    public function test_columns_returned_key_present(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('columns_returned', $data);
        self::assertIsArray($data['columns_returned']);
    }

    public function test_total_counts_every_matching_row_not_just_the_page(): void
    {
        $all = $this->meta($this->tool->execute([]));
        $page = $this->meta($this->tool->execute(['limit' => 1]));

        self::assertSame($all['total'], $page['total']);
        self::assertSame(1, $page['count']);
        self::assertGreaterThanOrEqual(1, $all['total']);
    }

    public function test_the_second_page_returns_rows_the_first_page_left(): void
    {
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'orders');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'orders');

        if ($second === []) {
            self::assertFalse($this->meta($this->tool->execute(['limit' => 1, 'offset' => 1]))['has_more']);

            return;
        }

        self::assertNotSame($first[0]['id'], $second[0]['id']);
    }

    public function test_an_employee_below_the_chat_tier_is_refused(): void
    {
        $this->actAsEmployeeBelowChatTier();

        self::assertSame('FORBIDDEN', $this->errorCode($this->tool->execute([])));
    }

    public function test_the_required_capability_is_the_chat_tab(): void
    {
        self::assertSame('AdminPhpClawDebug', $this->tool->requiredCapability());
    }

    public function test_an_unknown_argument_is_refused(): void
    {
        self::assertSame('UNKNOWN_ARGUMENT', $this->errorCode($this->tool->execute(['nope' => 1])));
    }

    public function test_an_out_of_range_limit_is_refused(): void
    {
        self::assertSame('INVALID_LIMIT', $this->errorCode($this->tool->execute(['limit' => 9999])));
    }

    public function test_valid_only_omitted_counts_every_order_row(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertCount(2, $data['orders']);
        self::assertSame(2, $data['total']);
        self::assertFalse($data['valid_only']);
    }

    public function test_valid_only_true_counts_only_orders_the_back_office_counts(): void
    {
        $data = $this->flat($this->tool->execute(['valid_only' => true]));

        self::assertCount(1, $data['orders']);
        self::assertSame('ABCDE', $data['orders'][0]['reference']);
        self::assertSame(1, $data['total']);
        self::assertTrue($data['valid_only']);
    }

    public function test_aggregate_honours_valid_only_through_the_same_clause_source(): void
    {
        $all = $this->flat($this->tool->execute(['aggregate' => true]));
        $valid = $this->flat($this->tool->execute(['aggregate' => true, 'valid_only' => true]));

        self::assertSame(2, $all['stats']['total_orders']);
        self::assertEqualsWithDelta(175.0, (float) $all['stats']['total_revenue'], 0.001);
        self::assertFalse($all['valid_only']);

        self::assertSame(1, $valid['stats']['total_orders']);
        self::assertEqualsWithDelta(120.0, (float) $valid['stats']['total_revenue'], 0.001);
        self::assertTrue($valid['valid_only']);
    }

    public function test_aggregate_count_agrees_with_the_page_it_describes(): void
    {
        $page = $this->flat($this->tool->execute(['valid_only' => true]));
        $agg = $this->flat($this->tool->execute(['aggregate' => true, 'valid_only' => true]));

        self::assertSame($page['total'], $agg['stats']['total_orders']);
    }

    public function test_schema_states_what_the_tool_counts_as_an_order(): void
    {
        $data = $this->flat($this->tool->execute(['schema' => true]));

        self::assertContains('valid_only', $data['filter_capabilities']);
        self::assertFalse($data['valid_only_default']);
        self::assertStringContainsString('every order row', $data['order_definition']);
        self::assertStringContainsString('counts towards sales', $data['order_definition']);
    }

    public function test_description_tells_the_model_what_counts_as_an_order(): void
    {
        $description = $this->tool->description();

        self::assertStringContainsString('WHAT COUNTS AS AN ORDER', $description);
        self::assertStringContainsString('valid_only', $description);
    }

    public function test_no_response_mode_carries_the_old_total_paid_name(): void
    {
        $modes = [
            'default' => [],
            'explicit' => ['columns' => ['reference', 'total_paid_tax_incl']],
            'wildcard' => ['columns' => ['*']],
            'aggregate' => ['aggregate' => true],
            'schema' => ['schema' => true],
        ];

        foreach ($modes as $label => $input) {
            $raw = $this->tool->execute($input);
            $decoded = $this->envelope($raw);

            self::assertTrue($decoded['success'], "mode {$label} refused");
            self::assertFalse(
                $this->carriesKey($decoded, 'total_paid'),
                "mode {$label} still carries the key \"total_paid\"",
            );
        }
    }

    public function test_the_returned_total_holds_the_tax_inclusive_column_not_total_paid(): void
    {
        $data = $this->flat($this->tool->execute(['columns' => ['reference', 'total_paid_tax_incl']]));

        $byReference = [];

        foreach ($data['orders'] as $order) {
            $byReference[$order['reference']] = $order['total_paid_tax_incl'];
        }

        self::assertEqualsWithDelta(120.0, (float) $byReference['ABCDE'], 0.001);
        self::assertEqualsWithDelta(55.0, (float) $byReference['FGHIJ'], 0.001);
    }

    public function test_the_fixture_makes_the_two_columns_differ_so_the_assertion_can_fail(): void
    {
        $rows = $this->db->query(
            "SELECT reference, total_paid, total_paid_tax_incl FROM `{$this->prefix}orders` ORDER BY id_order"
        )->rows;

        foreach ($rows as $row) {
            self::assertNotEquals(
                (float) $row['total_paid'],
                (float) $row['total_paid_tax_incl'],
                'The fixture must keep the two columns different, or the rename test proves nothing.',
            );
        }
    }

    public function test_schema_advertises_the_tax_inclusive_name(): void
    {
        $data = $this->flat($this->tool->execute(['schema' => true]));

        self::assertContains('total_paid_tax_incl', $data['available_columns']);
        self::assertContains('total_paid_tax_incl', $data['default_columns']);
        self::assertNotContains('total_paid', $data['available_columns']);
        self::assertNotContains('total_paid', $data['default_columns']);
    }

    private function carriesKey(array $envelope, string $key): bool
    {
        $found = false;

        array_walk_recursive(
            $envelope,
            static function ($value, $k) use ($key, &$found): void {
                if ($k === $key) {
                    $found = true;
                }
            },
        );

        if (! $found) {
            $found = in_array($key, $this->collectStrings($envelope), true);
        }

        return $found;
    }

    private function collectStrings(array $envelope): array
    {
        $out = [];

        array_walk_recursive(
            $envelope,
            static function ($value) use (&$out): void {
                if (is_string($value)) {
                    $out[] = $value;
                }
            },
        );

        return $out;
    }

    public function test_every_column_named_in_the_documentation_is_one_the_tool_returns(): void
    {
        $this->assertEveryDocumentedColumnIsEmitted($this->tool);
    }
}
