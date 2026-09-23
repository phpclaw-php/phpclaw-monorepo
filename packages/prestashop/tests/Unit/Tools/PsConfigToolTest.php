<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Tools;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\PrestaShop\Tests\Helpers\ActsAsChatTierEmployee;
use PhpClaw\PrestaShop\Tests\Helpers\AssertsToolEnvelope;
use PhpClaw\PrestaShop\Tests\Helpers\PsDbTestCase;
use PhpClaw\PrestaShop\Tools\PsConfigTool;
use PHPUnit\Framework\Attributes\DataProvider;

final class PsConfigToolTest extends PsDbTestCase
{
    use ActsAsChatTierEmployee;
    use AssertsToolEnvelope;

    private PsConfigTool $tool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actAsChatTierEmployee();

        $configTable = $this->prefix.'configuration';

        $this->resetTables([
            "CREATE TABLE IF NOT EXISTS `{$configTable}` (
                id_configuration INT          NOT NULL AUTO_INCREMENT,
                id_shop_group    INT          NULL DEFAULT NULL,
                id_shop          INT          NULL DEFAULT NULL,
                name             VARCHAR(254) NOT NULL DEFAULT '',
                value            MEDIUMTEXT,
                date_add         DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                date_upd         DATETIME     NOT NULL DEFAULT '2024-01-01 00:00:00',
                PRIMARY KEY (id_configuration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ]);

        $this->seed("INSERT INTO `{$configTable}` (name,value,date_add,date_upd) VALUES ('PS_SHOP_NAME','My Test Shop','2024-01-01 00:00:00','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$configTable}` (name,value,date_add,date_upd) VALUES ('PS_CURRENCY_DEFAULT','1','2024-01-01 00:00:00','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$configTable}` (name,value,date_add,date_upd) VALUES ('PS_MAIL_SERVER','smtp.example.com','2024-01-01 00:00:00','2024-01-01 00:00:00')");
        $this->seed("INSERT INTO `{$configTable}` (name,value,date_add,date_upd) VALUES ('PS_API_KEY','secret123','2024-01-01 00:00:00','2024-01-01 00:00:00')");

        $this->tool = new PsConfigTool($this->db, $this->prefix);
    }

    protected function tearDown(): void
    {
        $this->stopActingAsEmployee();

        parent::tearDown();
    }

    public function test_name_returns_ps_config(): void
    {
        self::assertSame('ps_config', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'Read PrestaShop configuration values',
            $this->tool->description(),
        );
    }

    public function test_execute_returns_configs_key(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        self::assertArrayHasKey('configs', $data);
        self::assertIsArray($data['configs']);
    }

    public function test_blocked_keys_not_returned(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        $names = array_column($data['configs'], 'name');
        self::assertNotContains('PS_API_KEY', $names);
    }

    public function test_non_blocked_keys_returned(): void
    {
        $result = $this->tool->execute([]);
        $data = $this->flat($result);

        $names = array_column($data['configs'], 'name');
        self::assertContains('PS_SHOP_NAME', $names);
    }

    public function test_search_filter_applied(): void
    {
        $result = $this->tool->execute(['search' => 'CURRENCY']);
        $data = $this->flat($result);

        $names = array_column($data['configs'], 'name');
        self::assertContains('PS_CURRENCY_DEFAULT', $names);
        self::assertNotContains('PS_SHOP_NAME', $names);
    }

    public function test_name_exact_match_filter(): void
    {
        $result = $this->tool->execute(['name' => 'PS_SHOP_NAME']);
        $data = $this->flat($result);

        self::assertCount(1, $data['configs']);
        self::assertSame('PS_SHOP_NAME', $data['configs'][0]['name']);
    }

    public function test_schema_mode_does_not_require_db(): void
    {
        $tool = new PsConfigTool(null, $this->prefix);

        $data = $this->flat($tool->execute(['schema' => true]));

        self::assertSame('schema', $data['mode']);
        self::assertSame(
            (new \ReflectionClassConstant(PsConfigTool::class, 'AVAILABLE_COLUMNS'))->getValue(),
            $data['available_columns'],
        );
        self::assertSame(
            (new \ReflectionClassConstant(PsConfigTool::class, 'BLOCKED_PATTERNS'))->getValue(),
            $data['blocked_patterns'],
        );
    }

    public function test_throws_on_null_db(): void
    {
        $tool = new PsConfigTool(null, $this->prefix);

        $this->expectException(ToolException::class);
        $tool->execute([]);
    }

    public function test_aggregate_mode_returns_total(): void
    {
        $result = $this->tool->execute(['aggregate' => true]);
        $data = $this->flat($result);

        self::assertSame('aggregate', $data['mode']);
        self::assertArrayHasKey('total', $data);
        self::assertGreaterThan(0, $data['total']);
        self::assertArrayHasKey('by_group', $data);
    }

    public function test_columns_wildcard_returns_all_columns(): void
    {
        $result = $this->tool->execute(['columns' => ['*']]);
        $data = $this->flat($result);

        self::assertContains('date_add', $data['columns_returned']);
        self::assertContains('date_upd', $data['columns_returned']);
    }

    public function test_columns_specific_subset(): void
    {
        $result = $this->tool->execute(['columns' => ['value']]);
        $data = $this->flat($result);

        self::assertContains('name', $data['columns_returned']);
        self::assertContains('value', $data['columns_returned']);
    }

    public function test_columns_invalid_filtered_out(): void
    {
        $result = $this->tool->execute(['columns' => ['nonexistent', 'value']]);
        $data = $this->flat($result);

        self::assertContains('value', $data['columns_returned']);
        self::assertNotContains('nonexistent', $data['columns_returned']);
    }

    public function test_order_dir_desc_accepted(): void
    {
        $data = $this->flat($this->tool->execute(['order_dir' => 'DESC']));

        self::assertSame(
            ['PS_SHOP_NAME', 'PS_MAIL_SERVER', 'PS_CURRENCY_DEFAULT'],
            array_column($data['configs'], 'name'),
        );
    }

    public function test_order_by_invalid_falls_back_to_name(): void
    {
        $data = $this->flat($this->tool->execute(['order_by' => 'injected_column']));

        self::assertSame(
            ['PS_CURRENCY_DEFAULT', 'PS_MAIL_SERVER', 'PS_SHOP_NAME'],
            array_column($data['configs'], 'name'),
        );
    }

    public function test_offset_applied(): void
    {
        $result = $this->tool->execute(['offset' => 0]);
        $result2 = $this->tool->execute(['offset' => 100]);
        $data = $this->flat($result);
        $data2 = $this->flat($result2);

        self::assertGreaterThanOrEqual(count($data2['configs']), count($data['configs']));
    }

    public function test_limit_applied(): void
    {
        $result = $this->tool->execute(['limit' => 1]);
        $data = $this->flat($result);

        self::assertLessThanOrEqual(1, count($data['configs']));
    }

    public function test_the_partial_redaction_limit_is_stated_in_the_response(): void
    {
        $result = $this->tool->execute([]);

        self::assertContains('PARTIAL_REDACTION', $this->warningCodes($result));
        self::assertArrayHasKey('blocked_patterns', $this->meta($result));
    }

    public function test_empty_result_returns_empty_configs(): void
    {
        $result = $this->tool->execute(['name' => 'PS_NONEXISTENT_99999']);
        $data = $this->flat($result);

        self::assertSame([], $data['configs']);
    }

    public function test_both_name_and_search_combined(): void
    {
        $result = $this->tool->execute(['name' => 'PS_SHOP_NAME', 'search' => 'SHOP']);
        $data = $this->flat($result);

        $names = array_column($data['configs'], 'name');
        self::assertContains('PS_SHOP_NAME', $names);
    }

    public function test_a_listing_describes_values_instead_of_returning_them(): void
    {
        $data = $this->flat($this->tool->execute([]));
        $row = $data['configs'][0];

        self::assertArrayNotHasKey('value', $row);
        self::assertArrayHasKey('value_type', $row);
        self::assertArrayHasKey('value_size', $row);
        self::assertFalse($data['values_included']);
    }

    public function test_a_named_lookup_returns_its_value_without_a_flag(): void
    {
        $data = $this->flat($this->tool->execute(['name' => 'PS_SHOP_NAME']));

        self::assertTrue($data['values_included']);
        self::assertSame('My Test Shop', $data['configs'][0]['value']);
    }

    public function test_a_json_value_is_decoded_before_it_is_filtered(): void
    {
        $table = $this->prefix.'configuration';
        $this->seed("INSERT INTO `{$table}` (name,value) VALUES ('MYMOD_GATEWAY','".
            addslashes(json_encode(['mode' => 'live', 'secret_key' => 'sk_live_LEAK', 'auth' => ['pass' => 'nested_LEAK']]))."')");

        $data = $this->flat($this->tool->execute(['name' => 'MYMOD_GATEWAY']));
        $value = $data['configs'][0]['value'];

        self::assertStringContainsString('live', $value);
        self::assertStringNotContainsString('sk_live_LEAK', $value);
        self::assertStringNotContainsString('nested_LEAK', $value);
    }

    public function test_a_serialised_value_is_decoded_before_it_is_filtered(): void
    {
        $table = $this->prefix.'configuration';
        $this->seed("INSERT INTO `{$table}` (name,value) VALUES ('MYMOD_MAILER','".
            addslashes(serialize(['host' => 'smtp.example.com', 'auth' => ['pass' => 'nested_LEAK']]))."')");

        $data = $this->flat($this->tool->execute(['name' => 'MYMOD_MAILER']));
        $value = $data['configs'][0]['value'];

        self::assertStringContainsString('smtp.example.com', $value);
        self::assertStringNotContainsString('nested_LEAK', $value);
    }

    public function test_a_scalar_is_withheld_when_the_name_is_the_key(): void
    {
        $table = $this->prefix.'configuration';
        $this->seed("INSERT INTO `{$table}` (name,value) VALUES ('MYMOD_THING_PASS','hunter2_LEAK')");

        $listed = $this->flat($this->tool->execute(['search' => 'MYMOD_THING_PASS']));
        self::assertCount(1, $listed['configs'], 'the row must survive the name filter, or this test proves nothing');

        $data = $this->flat($this->tool->execute(['name' => 'MYMOD_THING_PASS']));

        self::assertStringNotContainsString('hunter2_LEAK', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function test_credentials_embedded_in_a_url_are_stripped(): void
    {
        $table = $this->prefix.'configuration';
        $this->seed("INSERT INTO `{$table}` (name,value) VALUES ('MYMOD_ENDPOINT','https://admin:hunter2_LEAK@api.example.com/v1')");

        $data = $this->flat($this->tool->execute(['name' => 'MYMOD_ENDPOINT']));
        $value = $data['configs'][0]['value'];

        self::assertStringContainsString('api.example.com', $value);
        self::assertStringNotContainsString('hunter2_LEAK', $value);
    }

    public function test_an_ordinary_value_survives_the_scrub(): void
    {
        $data = $this->flat($this->tool->execute(['name' => 'PS_MAIL_SERVER']));

        self::assertSame('smtp.example.com', $data['configs'][0]['value']);
    }

    public function test_scope_columns_are_returned_by_default(): void
    {
        $data = $this->flat($this->tool->execute([]));

        self::assertArrayHasKey('id_shop', $data['configs'][0]);
        self::assertArrayHasKey('id_shop_group', $data['configs'][0]);
    }

    public function test_a_name_with_two_scopes_is_flagged_as_scoping_not_contradiction(): void
    {
        $table = $this->prefix.'configuration';
        $this->seed("INSERT INTO `{$table}` (id_shop,name,value) VALUES (NULL,'MYMOD_PER_SHOP','global')");
        $this->seed("INSERT INTO `{$table}` (id_shop,name,value) VALUES (1,'MYMOD_PER_SHOP','per shop')");

        $data = $this->flat($this->tool->execute(['name' => 'MYMOD_PER_SHOP']));

        self::assertCount(2, $data['configs']);
        self::assertContains('MYMOD_PER_SHOP', $data['names_with_multiple_scopes']);
        self::assertContains(
            'MULTIPLE_SHOP_SCOPES',
            $this->warningCodes($this->tool->execute(['name' => 'MYMOD_PER_SHOP'])),
        );
    }

    #[DataProvider('newlyBlockedNamePatterns')]
    public function test_a_widened_name_pattern_refuses_the_row(string $name): void
    {
        $table = $this->prefix.'configuration';
        $this->seed("INSERT INTO `{$table}` (name,value) VALUES ('".$name."','hunter2_LEAK')");

        $data = $this->flat($this->tool->execute(['search' => 'MYMOD']));

        self::assertNotContains($name, array_column($data['configs'], 'name'));
    }

    public static function newlyBlockedNamePatterns(): array
    {
        return [
            ['MYMOD_PWD'], ['MYMOD_CRED'], ['MYMOD_BEARER'], ['MYMOD_LICENSE'],
            ['MYMOD_PRIVATE'], ['MYMOD_CERT'], ['MYMOD_DSN'], ['MYMOD_PASSPHRASE'],
            ['MYMOD_NONCE'], ['MYMOD_HASH'],
        ];
    }

    public function test_names_measured_as_legitimate_are_still_readable(): void
    {
        $table = $this->prefix.'configuration';

        foreach (['PS_CHECKOUT_STATE_AUTHORIZED', 'PS_CUSTOMER_SERVICE_SIGNATURE', 'PS_ENABLE_ADMIN_API'] as $name) {
            $this->seed("INSERT INTO `{$table}` (name,value) VALUES ('".$name."','fine')");
        }

        $data = $this->flat($this->tool->execute(['search' => 'PS_', 'limit' => 100]));
        $names = array_column($data['configs'], 'name');

        foreach (['PS_CHECKOUT_STATE_AUTHORIZED', 'PS_CUSTOMER_SERVICE_SIGNATURE', 'PS_ENABLE_ADMIN_API'] as $name) {
            self::assertContains($name, $names, $name.' holds no credential and must stay readable');
        }
    }

    public function test_input_schema_offers_every_parameter_execute_accepts(): void
    {
        $schema = $this->tool->inputSchema();

        self::assertSame('object', $schema['type']);
        self::assertSame([], $schema['required']);

        $offered = array_keys($schema['properties']);
        sort($offered);

        $accepted = ['aggregate', 'columns', 'limit', 'name', 'offset', 'order_by', 'order_dir', 'schema', 'search'];

        self::assertSame(
            $accepted,
            $offered,
            'A parameter execute() honours but the schema omits is one no model will ever send.'
        );
    }

    public function test_input_schema_columns_description_names_the_real_column_set(): void
    {
        $schema = $this->tool->inputSchema();
        $description = $schema['properties']['columns']['description'];

        foreach (['name', 'value', 'id_shop', 'id_shop_group', 'date_add', 'date_upd'] as $column) {
            self::assertStringContainsString(
                $column,
                $description,
                $column.' is selectable but the schema does not tell the model it exists'
            );
        }
    }

    public function test_input_schema_bounds_match_the_enforced_limits(): void
    {
        $limit = $this->tool->inputSchema()['properties']['limit'];

        self::assertSame(1, $limit['minimum']);
        self::assertSame(100, $limit['maximum']);
        self::assertSame(30, $limit['default']);

        $table = $this->prefix.'configuration';
        for ($i = 0; $i < 3; $i++) {
            $this->seed("INSERT INTO `{$table}` (name,value) VALUES ('PS_BOUND_{$i}','v')");
        }

        self::assertSame(
            'INVALID_LIMIT',
            $this->errorCode($this->tool->execute(['search' => 'PS_BOUND_', 'limit' => 101])),
            'the advertised maximum must be the enforced one',
        );
        self::assertLessThanOrEqual(
            100,
            count($this->flat($this->tool->execute(['search' => 'PS_BOUND_', 'limit' => 100]))['configs']),
        );
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
        $first = $this->rows($this->tool->execute(['limit' => 1]), 'configs');
        $second = $this->rows($this->tool->execute(['limit' => 1, 'offset' => 1]), 'configs');

        if ($second === []) {
            self::assertFalse($this->meta($this->tool->execute(['limit' => 1, 'offset' => 1]))['has_more']);

            return;
        }

        self::assertNotSame($first[0], $second[0]);
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
}
