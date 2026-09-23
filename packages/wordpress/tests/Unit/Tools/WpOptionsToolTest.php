<?php

declare(strict_types=1);

namespace PhpClaw\WordPress\Tests\Unit\Tools;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PhpClaw\WordPress\Tools\WpOptionsTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[CoversClass(WpOptionsTool::class)]
final class WpOptionsToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_it_has_correct_name(): void
    {
        $tool = new WpOptionsTool;

        self::assertSame('wp_option', $tool->name());
    }

    public function test_it_reads_safe_option(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('blogname', false)
            ->andReturn('My Blog');

        $tool = new WpOptionsTool;
        $result = $tool->execute(['option_name' => 'blogname']);
        $data = json_decode($result, true);

        self::assertTrue($data['success']);
        self::assertSame('lookup', $data['meta']['mode']);
        self::assertSame('blogname', $data['data']['options'][0]['option_name']);
        self::assertSame('My Blog', $data['data']['options'][0]['value']);
        self::assertTrue($data['data']['options'][0]['found']);
    }

    #[DataProvider('blockedOptionProvider')]
    public function test_it_blocks_sensitive_option_names(string $optionName): void
    {
        $data = json_decode((new WpOptionsTool)->execute(['option_name' => $optionName]), true);

        self::assertFalse($data['success']);
        self::assertSame('BLOCKED_OPTION', $data['error']['code']);
        self::assertNull($data['data']);
    }

    public static function blockedOptionProvider(): array
    {
        return [
            'auth_key' => ['auth_key'],
            'secure_auth_key' => ['secure_auth_key'],
            'auth_salt' => ['auth_salt'],
            'nonce_key' => ['nonce_key'],
            'api_secret' => ['api_secret'],
            'stripe_secret' => ['stripe_secret'],
            'smtp_password' => ['smtp_password'],
            'nonce_salt' => ['nonce_salt'],
            'logged_in_key' => ['logged_in_key'],
        ];
    }

    public function test_it_throws_for_empty_option_name(): void
    {
        $data = json_decode((new WpOptionsTool)->execute(['option_name' => '']), true);

        self::assertFalse($data['success']);
        self::assertSame('INVALID_ARGUMENT', $data['error']['code']);
    }

    public function test_it_returns_empty_for_missing_option(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('nonexistent_option', false)
            ->andReturn(false);

        $tool = new WpOptionsTool;
        $result = $tool->execute(['option_name' => 'nonexistent_option']);
        $data = json_decode($result, true);

        self::assertSame('', $data['data']['options'][0]['value']);
        self::assertFalse($data['data']['options'][0]['found']);
    }

    public function test_it_json_encodes_array_values(): void
    {
        $arrayValue = ['key' => 'val', 'num' => 42];

        Functions\expect('get_option')
            ->once()
            ->with('some_option_array', false)
            ->andReturn($arrayValue);

        $tool = new WpOptionsTool;
        $result = $tool->execute(['option_name' => 'some_option_array']);
        $data = json_decode($result, true);

        self::assertStringContainsString('key', $data['data']['options'][0]['value']);
    }

    public function test_input_schema_has_option_name_property(): void
    {
        $tool = new WpOptionsTool;
        $schema = $tool->inputSchema();

        self::assertArrayHasKey('option_name', $schema['properties']);
    }

    public function test_schema_mode_returns_parameters_and_blocked_lists(): void
    {
        $result = json_decode((new WpOptionsTool)->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('schema', $result['meta']['mode']);
        self::assertFalse($result['meta']['database_query_performed']);
        self::assertContains('option_name', $result['data']['parameters']);
        self::assertContains('search', $result['data']['parameters']);
        self::assertContains('aggregate', $result['data']['parameters']);
        self::assertNotEmpty($result['data']['blocked_substrings']);
        self::assertNotEmpty($result['data']['blocked_exact']);
        self::assertSame('phpclaw_use_chat', $result['data']['wordpress_capability']);
    }

    public function test_aggregate_mode_returns_counts_via_wpdb(): void
    {
        $total = new \stdClass;
        $total->total_count = 250;
        $total->total_size = 1234567;

        $autoload = new \stdClass;
        $autoload->autoload_count = 80;

        $wpdb = new class($total, $autoload)
        {
            public string $options = 'wp_options';

            public function __construct(public object $total, public object $autoload) {}

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_row(string $sql): object
            {
                return stripos($sql, 'total_count') !== false ? $this->total : $this->autoload;
            }
        };

        $GLOBALS['wpdb'] = $wpdb;

        $result = json_decode((new WpOptionsTool)->execute(['aggregate' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('aggregate', $result['meta']['mode']);
        self::assertSame(250, $result['data']['total_count']);
        self::assertSame(80, $result['data']['autoloaded_count']);
        self::assertSame(1234567, $result['data']['total_size_bytes']);
        self::assertFalse($result['meta']['autoload_filter']);

        unset($GLOBALS['wpdb']);
    }

    public function test_aggregate_with_autoload_only_filter_sets_flag(): void
    {
        $total = new \stdClass;
        $total->total_count = 80;
        $total->total_size = 100000;

        $autoload = new \stdClass;
        $autoload->autoload_count = 80;

        $GLOBALS['wpdb'] = new class($total, $autoload)
        {
            public string $options = 'wp_options';

            public function __construct(public object $total, public object $autoload) {}

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_row(string $sql): object
            {
                return stripos($sql, 'total_count') !== false ? $this->total : $this->autoload;
            }
        };

        $result = json_decode((new WpOptionsTool)->execute(['aggregate' => true, 'autoload_only' => true]), true);

        self::assertSame(80, $result['data']['total_count']);
        self::assertTrue($result['meta']['autoload_filter']);

        unset($GLOBALS['wpdb']);
    }

    public function test_aggregate_throws_when_wpdb_missing(): void
    {
        unset($GLOBALS['wpdb']);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/\$wpdb is not available/');

        (new WpOptionsTool)->execute(['aggregate' => true]);
    }

    public function test_aggregate_wraps_throwable_in_tool_exception(): void
    {
        $GLOBALS['wpdb'] = new class
        {
            public string $options = 'wp_options';

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_row(string $sql): never
            {
                throw new \RuntimeException('db down');
            }
        };

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/aggregate query failed/');

        try {
            (new WpOptionsTool)->execute(['aggregate' => true]);
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function test_search_returns_matching_options(): void
    {
        $GLOBALS['wpdb'] = new class
        {
            public string $options = 'wp_options';

            public string $last_error = '';

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function get_var(string $sql): string
            {
                return '3';
            }

            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                return [
                    ['option_name' => 'blogname',  'option_value' => 'My Blog',  'autoload' => 'yes'],
                    ['option_name' => 'blogdescription', 'option_value' => 'Tagline', 'autoload' => 'yes'],
                ];
            }
        };

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'blog']), true);

        self::assertCount(2, $result['data']['options']);
        self::assertSame('blogname', $result['data']['options'][0]['option_name']);
        self::assertSame('yes', $result['data']['options'][0]['autoload']);
        self::assertSame(3, $result['meta']['total']);
        self::assertSame(2, $result['meta']['count']);
        self::assertTrue($result['meta']['has_more']);
        self::assertSame(2, $result['meta']['next_offset']);

        unset($GLOBALS['wpdb']);
    }

    public function test_search_skips_blocked_options(): void
    {
        $GLOBALS['wpdb'] = new class
        {
            public string $options = 'wp_options';

            public string $last_error = '';

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function get_var(string $sql): string
            {
                return '3';
            }

            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                return [
                    ['option_name' => 'auth_key',    'option_value' => 'SECRET',  'autoload' => 'no'],
                    ['option_name' => 'stripe_secret', 'option_value' => 'sk_xxx', 'autoload' => 'no'],
                    ['option_name' => 'blogname',    'option_value' => 'OK',      'autoload' => 'yes'],
                ];
            }
        };

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'foo']), true);

        self::assertCount(1, $result['data']['options']);
        self::assertSame('blogname', $result['data']['options'][0]['option_name']);

        unset($GLOBALS['wpdb']);
    }

    public function test_search_truncates_long_values_at_500(): void
    {
        $long = str_repeat('Z', 600);

        $GLOBALS['wpdb'] = new class($long)
        {
            public string $options = 'wp_options';

            public function __construct(public string $long) {}

            public string $last_error = '';

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function get_var(string $sql): string
            {
                return '3';
            }

            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                return [
                    ['option_name' => 'big_option', 'option_value' => $this->long, 'autoload' => 'no'],
                ];
            }
        };

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'big', 'include_values' => true]), true);

        self::assertStringEndsWith('...[truncated]', $result['data']['options'][0]['value']);
        self::assertLessThanOrEqual(500 + 20, strlen($result['data']['options'][0]['value']));

        unset($GLOBALS['wpdb']);
    }

    public function test_search_throws_when_wpdb_missing(): void
    {
        unset($GLOBALS['wpdb']);

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/\$wpdb is not available/');

        (new WpOptionsTool)->execute(['search' => 'blog']);
    }

    public function test_search_wraps_throwable_in_tool_exception(): void
    {
        $GLOBALS['wpdb'] = new class
        {
            public string $options = 'wp_options';

            public string $last_error = '';

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function get_var(string $sql): string
            {
                return '3';
            }

            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): never
            {
                throw new \RuntimeException('db boom');
            }
        };

        $this->expectException(ToolException::class);
        $this->expectExceptionMessageMatches('/search query failed/');

        try {
            (new WpOptionsTool)->execute(['search' => 'blog']);
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function test_search_with_autoload_only_appends_filter(): void
    {
        $GLOBALS['wpdb'] = new class
        {
            public string $options = 'wp_options';

            public string $lastSql = '';

            public string $last_error = '';

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function get_var(string $sql): string
            {
                return '3';
            }

            public function prepare(string $sql, ...$args): string
            {
                $this->lastSql = $sql;

                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                return [];
            }
        };

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'foo', 'autoload_only' => true]), true);

        self::assertTrue($result['meta']['autoload_filter']);

        unset($GLOBALS['wpdb']);
    }

    public function test_single_lookup_with_scalar_returns_string_value(): void
    {
        Functions\expect('get_option')->once()->with('admin_email', false)->andReturn('admin@example.com');

        $result = json_decode((new WpOptionsTool)->execute(['option_name' => 'admin_email']), true);

        self::assertSame('admin@example.com', $result['data']['options'][0]['value']);
        self::assertTrue($result['data']['options'][0]['found']);
    }

    public function test_blank_option_name_falls_through_to_throw(): void
    {
        $data = json_decode((new WpOptionsTool)->execute(['option_name' => '   ']), true);

        self::assertFalse($data['success']);
        self::assertSame('INVALID_ARGUMENT', $data['error']['code']);
    }

    public function test_blank_search_falls_through_to_throw(): void
    {
        $data = json_decode((new WpOptionsTool)->execute(['search' => '   ']), true);

        self::assertFalse($data['success']);
        self::assertSame('INVALID_SEARCH', $data['error']['code']);
    }

    #[RunInSeparateProcess]
    public function test_autoload_filter_uses_wp_66_value_set_when_core_function_exists(): void
    {
        Functions\expect('wp_autoload_values_to_autoload')
            ->once()
            ->andReturn(['yes', 'on', 'auto-on', 'auto']);

        $capturedArgs = [];

        $GLOBALS['wpdb'] = new class($capturedArgs)
        {
            public string $options = 'wp_options';

            public function __construct(public array &$captured) {}

            public string $last_error = '';

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function get_var(string $sql): string
            {
                return '3';
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                $this->captured = $args;

                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                return [];
            }
        };

        (new WpOptionsTool)->execute(['search' => 'foo', 'autoload_only' => true]);

        self::assertSame(['%foo%', 'yes', 'on', 'auto-on', 'auto', 50, 0], $capturedArgs);

        unset($GLOBALS['wpdb']);
    }

    #[RunInSeparateProcess]
    public function test_autoload_filter_falls_back_to_legacy_two_value_set_when_core_function_absent(): void
    {
        $capturedArgs = [];

        $GLOBALS['wpdb'] = new class($capturedArgs)
        {
            public string $options = 'wp_options';

            public function __construct(public array &$captured) {}

            public string $last_error = '';

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function get_var(string $sql): string
            {
                return '3';
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                $this->captured = $args;

                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                return [];
            }
        };

        (new WpOptionsTool)->execute(['search' => 'foo', 'autoload_only' => true]);

        self::assertSame(['%foo%', 'yes', 'on', 50, 0], $capturedArgs);

        unset($GLOBALS['wpdb']);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new WpOptionsTool)->execute(['option_name' => 'blogname']));
    }

    public function test_forbidden_runs_no_option_read(): void
    {
        $this->denyAllCapabilities();
        Functions\expect('get_option')->never();

        (new WpOptionsTool)->execute(['option_name' => 'blogname']);
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $data = json_decode((new WpOptionsTool)->execute(['nope' => 1]), true);

        self::assertFalse($data['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $data['error']['code']);
        self::assertContains('option_name', $data['error']['accepted_arguments']);
    }

    public function test_it_rejects_conflicting_modes(): void
    {
        $data = json_decode((new WpOptionsTool)->execute(['schema' => true, 'aggregate' => true]), true);

        self::assertFalse($data['success']);
        self::assertSame('CONFLICTING_MODES', $data['error']['code']);
    }

    public function test_it_rejects_an_out_of_range_limit_instead_of_clamping(): void
    {
        $data = json_decode((new WpOptionsTool)->execute(['search' => 'a', 'limit' => 5000]), true);

        self::assertFalse($data['success']);
        self::assertSame('INVALID_LIMIT', $data['error']['code']);
    }

    public function test_it_rejects_a_negative_offset(): void
    {
        $data = json_decode((new WpOptionsTool)->execute(['search' => 'a', 'offset' => -1]), true);

        self::assertFalse($data['success']);
        self::assertSame('INVALID_OFFSET', $data['error']['code']);
    }

    public function test_aggregate_warns_when_search_arguments_are_ignored(): void
    {
        $total = new \stdClass;
        $total->total_count = 4;
        $total->total_size = 100;

        $autoload = new \stdClass;
        $autoload->autoload_count = 2;

        $GLOBALS['wpdb'] = new class($total, $autoload)
        {
            public string $options = 'wp_options';

            public string $last_error = '';

            public function __construct(public object $total, public object $autoload) {}

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_row(string $sql): object
            {
                return stripos($sql, 'total_count') !== false ? $this->total : $this->autoload;
            }
        };

        $result = json_decode((new WpOptionsTool)->execute(['aggregate' => true, 'search' => 'x']), true);

        self::assertTrue($result['success']);
        self::assertSame('IGNORED_ARGUMENT', $result['warnings'][0]['code']);

        unset($GLOBALS['wpdb']);
    }

    public function test_search_reports_blocked_options_instead_of_silently_dropping_them(): void
    {
        $GLOBALS['wpdb'] = new class
        {
            public string $options = 'wp_options';

            public string $last_error = '';

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function get_var(string $sql): string
            {
                return '2';
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                return [
                    ['option_name' => 'auth_key', 'option_value' => 'SECRET', 'autoload' => 'no'],
                    ['option_name' => 'blogname', 'option_value' => 'OK', 'autoload' => 'yes'],
                ];
            }
        };

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'a']), true);

        self::assertCount(1, $result['data']['options']);
        self::assertSame('BLOCKED_OPTION', $result['warnings'][0]['code']);
        self::assertStringContainsString('auth_key', $result['warnings'][0]['message']);

        unset($GLOBALS['wpdb']);
    }

    public function test_search_throws_when_the_database_reports_an_error(): void
    {
        $GLOBALS['wpdb'] = new class
        {
            public string $options = 'wp_options';

            public string $last_error = '';

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function get_var(string $sql): string
            {
                $this->last_error = 'MySQL server has gone away';

                return '0';
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                return [];
            }
        };

        $this->expectException(ToolException::class);

        try {
            (new WpOptionsTool)->execute(['search' => 'a']);
        } finally {
            unset($GLOBALS['wpdb']);
        }
    }

    public function test_multiple_option_names_return_one_row_each(): void
    {
        Functions\expect('get_option')->once()->with('blogname', false)->andReturn('A');
        Functions\expect('get_option')->once()->with('siteurl', false)->andReturn('B');

        $result = json_decode(
            (new WpOptionsTool)->execute(['option_name' => ['blogname', 'siteurl']]),
            true,
        );

        self::assertTrue($result['success']);
        self::assertSame('lookup', $result['meta']['mode']);
        self::assertCount(2, $result['data']['options']);
        self::assertSame(2, $result['meta']['requested']);
    }

    private function stubOptionRow(string $name, string $storedValue): void
    {
        $GLOBALS['wpdb'] = new class($name, $storedValue)
        {
            public string $options = 'wp_options';

            public string $last_error = '';

            public function __construct(public string $name, public string $stored) {}

            public function esc_like(string $s): string
            {
                return $s;
            }

            public function get_var(string $sql): string
            {
                return '1';
            }

            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            public function get_results(string $sql, mixed $output = null): array
            {
                return [['option_name' => $this->name, 'option_value' => $this->stored, 'autoload' => 'no']];
            }
        };
    }

    public function test_search_returns_metadata_and_no_values_by_default(): void
    {
        $this->stubOptionRow('woocommerce_stripe_settings', serialize(['secret_key' => 'sk_live_LEAK']));

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'stripe']), true);
        $row = $result['data']['options'][0];

        self::assertArrayNotHasKey('value', $row);
        self::assertSame('array', $row['value_type']);
        self::assertGreaterThan(0, $row['value_size']);
        self::assertStringNotContainsString('sk_live_LEAK', json_encode($result, JSON_THROW_ON_ERROR));

        unset($GLOBALS['wpdb']);
    }

    public function test_a_credential_key_inside_an_array_value_is_withheld(): void
    {
        $this->stubOptionRow('woocommerce_stripe_settings', serialize([
            'title' => 'Credit Card',
            'secret_key' => 'sk_live_LEAK',
            'webhook_secret' => 'whsec_LEAK',
        ]));

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'stripe', 'include_values' => true]), true);
        $value = $result['data']['options'][0]['value'];

        self::assertStringContainsString('Credit Card', $value);
        self::assertStringNotContainsString('sk_live_LEAK', $value);
        self::assertStringNotContainsString('whsec_LEAK', $value);

        unset($GLOBALS['wpdb']);
    }

    public function test_a_credential_key_nested_one_level_deeper_is_withheld(): void
    {
        $this->stubOptionRow('mailer_smtp_options', serialize([
            'host' => 'smtp.example.com',
            'auth' => ['user' => 'postmaster', 'pass' => 'hunter2_LEAK'],
        ]));

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'smtp', 'include_values' => true]), true);
        $value = $result['data']['options'][0]['value'];

        self::assertStringContainsString('smtp.example.com', $value);
        self::assertStringNotContainsString('hunter2_LEAK', $value);

        unset($GLOBALS['wpdb']);
    }

    public function test_a_scalar_value_is_withheld_when_the_option_name_is_the_key(): void
    {
        $this->stubOptionRow('myplugin_pwd', 'hunter2_LEAK');

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'myplugin', 'include_values' => true]), true);

        self::assertStringNotContainsString('hunter2_LEAK', json_encode($result, JSON_THROW_ON_ERROR));

        unset($GLOBALS['wpdb']);
    }

    public function test_credentials_embedded_in_a_url_are_stripped(): void
    {
        $this->stubOptionRow('myplugin_endpoint', 'https://admin:hunter2_LEAK@api.example.com/v1');

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'myplugin', 'include_values' => true]), true);
        $value = $result['data']['options'][0]['value'];

        self::assertStringContainsString('api.example.com', $value);
        self::assertStringNotContainsString('hunter2_LEAK', $value);

        unset($GLOBALS['wpdb']);
    }

    public function test_an_ordinary_value_survives_the_scrub(): void
    {
        $this->stubOptionRow('blogname', 'My Site');

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'blogname', 'include_values' => true]), true);

        self::assertSame('My Site', $result['data']['options'][0]['value']);

        unset($GLOBALS['wpdb']);
    }

    public function test_the_warning_names_both_limits_of_the_blocklist(): void
    {
        $this->stubOptionRow('blogname', 'My Site');

        $result = json_decode((new WpOptionsTool)->execute(['search' => 'blogname', 'include_values' => true]), true);
        $codes = array_column($result['warnings'], 'code');
        $index = array_search('VALUE_SCRUBBING_IS_PARTIAL', $codes, true);

        self::assertNotFalse($index, 'the scrubbing limit must be stated on every path that returns a value');

        $message = $result['warnings'][$index]['message'];

        self::assertStringContainsString('under a key nobody listed', $message);
        self::assertStringContainsString('unremarkable', $message);
        self::assertStringContainsString('do not treat these values as scrubbed', strtolower($message));

        unset($GLOBALS['wpdb']);
    }

    public function test_description_names_the_capability_the_tool_checks(): void
    {
        $tool = (new WpOptionsTool);

        self::assertStringContainsString('"'.$tool->requiredCapability().'"', $tool->description());
    }
}
