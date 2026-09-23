<?php

declare(strict_types=1);

namespace PhpClaw\WooCommerce\Tests\Unit\Tools;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PhpClaw\WooCommerce\Tools\ShippingTool;
use PhpClaw\WordPress\Tests\Concerns\StubsCapabilities;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShippingTool::class)]
final class ShippingToolTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use StubsCapabilities;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->grantCapability('phpclaw_use_chat');

        \WC_Shipping_Zones::$testZones = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_name_is_wc_shipping(): void
    {
        self::assertSame('wc_shipping', (new ShippingTool)->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        self::assertStringContainsString(
            'WooCommerce shipping zones',
            (new ShippingTool)->description(),
        );
    }

    public function test_execute_returns_rest_of_world_zone(): void
    {
        \WC_Shipping_Zones::$testZones = [];

        $result = json_decode((new ShippingTool)->execute([]), true);

        self::assertArrayHasKey('zones', $result['data']);
        self::assertGreaterThanOrEqual(1, $result['meta']['total']);
        self::assertSame(0, $result['data']['zones'][0]['id']);
        self::assertSame('Rest of the World', $result['data']['zones'][0]['name']);
    }

    public function test_execute_returns_configured_zones(): void
    {
        $method = \Mockery::mock('stdClass');
        $method->id = 'flat_rate';
        $method->shouldReceive('get_title')->andReturn('Flat Rate');
        $method->shouldReceive('is_enabled')->andReturn(true);
        $method->shouldReceive('get_option')->andReturnUsing(
            static fn (string $key, mixed $default = null): mixed => $key === 'cost' ? '5.00' : $default,
        );

        \WC_Shipping_Zones::$testZones = [
            1 => [
                'id' => 1,
                'zone_name' => 'US Domestic',
                'zone_locations' => [(object) ['type' => 'country', 'code' => 'US']],
                'shipping_methods' => [$method],
            ],
        ];

        $result = json_decode((new ShippingTool)->execute(['include_settings' => true]), true);

        self::assertSame(2, $result['meta']['total']);

        $usZone = null;
        foreach ($result['data']['zones'] as $z) {
            if ($z['id'] === 1) {
                $usZone = $z;
                break;
            }
        }
        self::assertNotNull($usZone);
        self::assertSame('US Domestic', $usZone['name']);
        self::assertSame('Flat Rate', $usZone['methods'][0]['title']);
        self::assertSame('5.00', $usZone['methods'][0]['settings']['cost']);
    }

    private function methodDouble(string $methodId, int $instanceId, string $title, array $settings): object
    {
        return new class($methodId, $instanceId, $title, $settings)
        {
            public function __construct(
                public string $id,
                public int $instance_id,
                private string $title,
                private array $settings,
            ) {}

            public function get_title(): string
            {
                return $this->title;
            }

            public function is_enabled(): bool
            {
                return true;
            }

            public function get_option(string $key, mixed $default = null): mixed
            {
                return $this->settings[$key] ?? $default;
            }
        };
    }

    private function zoneFetcher(array $zones): callable
    {
        return static fn (): array => $zones;
    }

    public function test_credential_keys_are_never_returned_even_when_present(): void
    {
        $method = $this->methodDouble('flat_rate', 1, 'EU Flat Rate', [
            'title' => 'EU Flat Rate',
            'tax_status' => 'taxable',
            'cost' => '12.50',
            'api_key' => 'sk_live_SHOULD_NEVER_APPEAR',
            'account_number' => 'ACCT-999-SECRET',
            'auth_token' => 'tok_SHOULD_NEVER_APPEAR',
            'carrier_password' => 'hunter2',
        ]);

        $tool = new ShippingTool($this->zoneFetcher([
            ['id' => 1, 'zone_name' => 'EU', 'zone_locations' => [], 'shipping_methods' => [$method]],
        ]));

        $raw = $tool->execute(['include_settings' => true]);
        $r = json_decode($raw, true);

        $settings = $r['data']['zones'][0]['methods'][0]['settings'];

        self::assertSame(['title', 'tax_status', 'cost'], array_keys($settings));
        self::assertSame('12.50', $settings['cost']);

        foreach (['sk_live_SHOULD_NEVER_APPEAR', 'ACCT-999-SECRET', 'tok_SHOULD_NEVER_APPEAR', 'hunter2'] as $secret) {
            self::assertStringNotContainsString($secret, $raw, 'a credential reached the response body');
        }
    }

    public function test_unknown_method_type_returns_no_settings_at_all(): void
    {
        $method = $this->methodDouble('acme_carrier_pro', 4, 'ACME Carrier', [
            'title' => 'ACME Carrier',
            'cost' => '30.00',
            'shipper_number' => 'SHIP-123',
        ]);

        $tool = new ShippingTool($this->zoneFetcher([
            ['id' => 1, 'zone_name' => 'EU', 'zone_locations' => [], 'shipping_methods' => [$method]],
        ]));

        $r = json_decode($tool->execute(['include_settings' => true]), true);
        $row = $r['data']['zones'][0]['methods'][0];

        self::assertArrayNotHasKey('settings', $row);
        self::assertSame('ACME Carrier', $row['title']);
        self::assertSame('acme_carrier_pro', $row['method_id']);
        self::assertContains('acme_carrier_pro', $r['meta']['unclassified_methods']);
        self::assertSame('SETTINGS_OMITTED', $r['warnings'][0]['code']);
    }

    public function test_settings_are_omitted_unless_requested(): void
    {
        $method = $this->methodDouble('flat_rate', 1, 'Flat', ['title' => 'Flat', 'cost' => '5']);

        $tool = new ShippingTool($this->zoneFetcher([
            ['id' => 1, 'zone_name' => 'EU', 'zone_locations' => [], 'shipping_methods' => [$method]],
        ]));

        $r = json_decode($tool->execute([]), true);

        self::assertArrayNotHasKey('settings', $r['data']['zones'][0]['methods'][0]);
        self::assertFalse($r['meta']['settings_included']);
    }

    public function test_zone_locations_are_returned_as_configuration(): void
    {
        $loc1 = (object) ['type' => 'country', 'code' => 'FR'];
        $loc2 = (object) ['type' => 'postcode', 'code' => 'SW1A'];

        $tool = new ShippingTool($this->zoneFetcher([
            ['id' => 2, 'zone_name' => 'UK', 'zone_locations' => [$loc1, $loc2], 'shipping_methods' => []],
        ]));

        $r = json_decode($tool->execute([]), true);

        self::assertSame(
            [['type' => 'country', 'code' => 'FR'], ['type' => 'postcode', 'code' => 'SW1A']],
            $r['data']['zones'][0]['locations'],
        );
    }

    public function test_zones_are_returned_in_a_deterministic_order(): void
    {
        $tool = new ShippingTool($this->zoneFetcher([
            ['id' => 5, 'zone_name' => 'E', 'zone_locations' => [], 'shipping_methods' => []],
            ['id' => 0, 'zone_name' => 'Rest', 'zone_locations' => [], 'shipping_methods' => []],
            ['id' => 2, 'zone_name' => 'B', 'zone_locations' => [], 'shipping_methods' => []],
        ]));

        $r = json_decode($tool->execute([]), true);

        self::assertSame([0, 2, 5], array_column($r['data']['zones'], 'id'));
    }

    public function test_zone_id_filter_selects_one_zone(): void
    {
        $tool = new ShippingTool($this->zoneFetcher([
            ['id' => 0, 'zone_name' => 'Rest', 'zone_locations' => [], 'shipping_methods' => []],
            ['id' => 2, 'zone_name' => 'UK', 'zone_locations' => [], 'shipping_methods' => []],
        ]));

        $r = json_decode($tool->execute(['zone_id' => 2]), true);

        self::assertSame(1, $r['meta']['count']);
        self::assertSame('UK', $r['data']['zones'][0]['name']);
    }

    public function test_it_returns_forbidden_without_the_required_capability(): void
    {
        $this->denyAllCapabilities();

        $this->assertForbiddenEnvelope((new ShippingTool($this->zoneFetcher([])))->execute([]));
    }

    public function test_forbidden_reads_no_zones(): void
    {
        $this->denyAllCapabilities();
        $fetched = false;

        $tool = new ShippingTool(function () use (&$fetched): array {
            $fetched = true;

            return [];
        });

        $tool->execute([]);

        self::assertFalse($fetched);
    }

    public function test_it_returns_unknown_argument_instead_of_throwing(): void
    {
        $r = json_decode((new ShippingTool($this->zoneFetcher([])))->execute(['phpclaw_bogus' => 1]), true);

        self::assertFalse($r['success']);
        self::assertSame('UNKNOWN_ARGUMENT', $r['error']['code']);
    }

    public function test_schema_publishes_the_allowlist_and_denylist(): void
    {
        $r = json_decode((new ShippingTool($this->zoneFetcher([])))->execute(['schema' => true]), true);

        self::assertArrayHasKey('flat_rate', $r['data']['allowlisted_settings']);
        self::assertContains('token', $r['data']['credential_key_patterns']);
        self::assertSame([], $r['data']['sensitive_columns']);
    }

    public function test_contract_accessors_report_the_declared_capability_risk_and_examples(): void
    {
        $tool = new ShippingTool;

        self::assertSame('woocommerce.shipping.read', $tool->capability());
        self::assertSame('phpclaw_use_chat', $tool->requiredCapability());
        self::assertSame('read', $tool->risk());
        self::assertTrue($tool->isIdempotent());
        self::assertNotSame([], ShippingTool::examples());
    }

    public function test_schema_mode_describes_the_tool_without_running_a_query(): void
    {
        $result = json_decode((new ShippingTool)->execute(['schema' => true]), true);

        self::assertTrue($result['success']);
        self::assertSame('schema', $result['meta']['mode']);
        self::assertSame('woocommerce.shipping.read', $result['data']['phpclaw_capability']);
        self::assertSame('read', $result['data']['risk']);
        self::assertTrue($result['data']['idempotent']);
        self::assertContains('schema', $result['data']['modes']);
        self::assertNotSame([], $result['data']['examples']);
    }
}
