<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Tools;

use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Tools\AbstractLaravelTool;
use PhpClaw\Laravel\Tools\ConfigGetTool;
use PHPUnit\Framework\Attributes\DataProvider;

final class ConfigGetToolTest extends TestCase
{
    private ConfigGetTool $tool;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new ConfigGetTool;
    }

    public function test_name_returns_config_get(): void
    {
        $this->assertSame('config_get', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $description = $this->tool->description();

        $this->assertStringContainsString('READ a single Laravel config value by dot-notation key', $description);
        $this->assertStringContainsString('are redacted', $description);
    }

    public function test_input_schema_requires_key(): void
    {
        $schema = $this->tool->inputSchema();

        $this->assertContains('key', $schema['required']);
    }

    public function test_required_capability_returns_chat_ability(): void
    {
        $this->assertSame('phpclaw.chat', $this->tool->requiredCapability());
    }

    public function test_execute_returns_success_envelope_with_expected_keys(): void
    {
        config(['services.phpclaw_test.base_url' => 'https://example.com']);

        $raw = $this->tool->execute(['key' => 'services.phpclaw_test.base_url']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertArrayHasKey('data', $envelope);
        $this->assertArrayHasKey('meta', $envelope);
        $this->assertArrayHasKey('warnings', $envelope);
        $this->assertSame('https://example.com', $envelope['data']['value']);
    }

    public function test_execute_returns_invalid_argument_on_empty_key(): void
    {
        $raw = $this->tool->execute(['key' => '']);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
    }

    public function test_execute_returns_not_found_warning_for_unset_key(): void
    {
        $raw = $this->tool->execute(['key' => 'app.totally_nonexistent_setting']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertNull($envelope['data']['value']);

        $codes = array_column($envelope['warnings'], 'code');
        $this->assertContains('NOT_FOUND', $codes);
    }

    public function test_execute_returns_error_envelope_for_array_value(): void
    {
        $raw = $this->tool->execute(['key' => 'cache.stores']);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
        $this->assertStringContainsString('resolves to an array', $envelope['error']['message']);
    }

    public function test_execute_returns_a_distinct_error_for_a_non_scalar_value(): void
    {
        config(['phpclaw_test.closure' => static fn () => 'unused']);

        $raw = $this->tool->execute(['key' => 'phpclaw_test.closure']);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
        $this->assertStringContainsString('non-scalar value', $envelope['error']['message']);
        $this->assertStringNotContainsString('resolves to an array', $envelope['error']['message']);
    }

    #[DataProvider('newlySecretPatterns')]
    public function test_a_newly_added_pattern_redacts_the_value(string $segment): void
    {
        config(["services.myapi.{$segment}" => 'hunter2_LEAK']);

        $raw = $this->tool->execute(['key' => "services.myapi.{$segment}"]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $codes = array_column($envelope['warnings'], 'code');
        $this->assertContains('REDACTED', $codes);
    }

    public static function newlySecretPatterns(): array
    {
        return [
            ['pwd'], ['bearer'], ['access'], ['cred'], ['license'],
            ['pin'], ['otp'], ['seed'], ['jwt'],
        ];
    }

    public function test_execute_returns_redacted_warning_for_secret_named_key(): void
    {
        config(['services.stripe.secret' => 'sk_test_hunter2_LEAK']);

        $raw = $this->tool->execute(['key' => 'services.stripe.secret']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame('***withheld***', $envelope['data']['value']);

        $codes = array_column($envelope['warnings'], 'code');
        $this->assertContains('REDACTED', $codes);
    }

    public function test_url_credentials_are_stripped_from_a_key_the_patterns_allow(): void
    {
        config(['services.myapi.endpoint' => 'https://admin:hunter2_LEAK@api.example.com/v1']);

        $raw = $this->tool->execute(['key' => 'services.myapi.endpoint']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $codes = array_column($envelope['warnings'], 'code');
        $this->assertNotContains('REDACTED', $codes, 'the value must reach the strip, not be caught by a name pattern');

        $this->assertStringContainsString('api.example.com', $envelope['data']['value']);
        $this->assertStringNotContainsString('hunter2_LEAK', $envelope['data']['value']);
    }

    public function test_url_credentials_are_stripped_outside_the_database_prefix(): void
    {
        config(['queue.connections.myq.url' => 'redis://user:hunter2_LEAK@redis.internal:6379']);

        $raw = $this->tool->execute(['key' => 'queue.connections.myq.url']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $codes = array_column($envelope['warnings'], 'code');
        $this->assertNotContains('REDACTED', $codes, 'the value must reach the strip');

        $this->assertStringContainsString('redis.internal', $envelope['data']['value']);
        $this->assertStringNotContainsString('hunter2_LEAK', $envelope['data']['value']);
    }

    public function test_an_ordinary_value_survives_untouched(): void
    {
        config(['services.myapi.endpoint' => 'https://api.example.com/v1']);

        $raw = $this->tool->execute(['key' => 'services.myapi.endpoint']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame('https://api.example.com/v1', $envelope['data']['value']);
    }

    public function test_an_array_value_is_refused_rather_than_returned(): void
    {
        config(['services.myapi.settings' => ['token' => 'hunter2_LEAK', 'mode' => 'live']]);

        $raw = $this->tool->execute(['key' => 'services.myapi.settings']);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
    }

    public function test_the_array_refusal_does_not_disclose_the_structure(): void
    {
        config(['services.myapi.settings' => ['token' => 'hunter2_LEAK', 'mode' => 'live']]);

        $raw = $this->tool->execute(['key' => 'services.myapi.settings']);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertStringNotContainsString('hunter2_LEAK', $envelope['error']['message']);
        $this->assertStringNotContainsString('token', $envelope['error']['message']);
        $this->assertStringNotContainsString('mode', $envelope['error']['message']);
    }

    public function test_the_high_value_laravel_keys_are_all_redacted(): void
    {
        foreach ([
            'app.key', 'app.cipher',
            'database.connections.mysql.password',
            'mail.mailers.smtp.password',
            'services.stripe.secret', 'services.ses.key',
            'queue.connections.sqs.secret', 'filesystems.disks.s3.secret',
            'broadcasting.connections.pusher.secret',
        ] as $configKey) {
            config([$configKey => 'hunter2_LEAK']);

            $raw = $this->tool->execute(['key' => $configKey]);
            $envelope = json_decode($raw, associative: true);

            $this->assertTrue($envelope['success'], $configKey.' must return a success envelope');

            $codes = array_column($envelope['warnings'], 'code');
            $this->assertContains('REDACTED', $codes, $configKey.' must carry the REDACTED warning');
        }
    }

    public static function redundantCandidates(): array
    {
        return [['oauth', 'auth'], ['apikey', 'key']];
    }

    #[DataProvider('redundantCandidates')]
    public function test_a_candidate_already_covered_is_redacted_by_an_existing_pattern(string $candidate, string $covers): void
    {
        config(["services.myapi.{$candidate}" => 'hunter2_LEAK']);

        $raw = $this->tool->execute(['key' => "services.myapi.{$candidate}"]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success'], $candidate.' must be redacted (covered by '.$covers.')');

        $codes = array_column($envelope['warnings'], 'code');
        $this->assertContains('REDACTED', $codes, $candidate.' must carry the REDACTED warning');
    }

    public function test_capability_guard_returns_forbidden_when_no_authenticated_user_and_gate_undefined(): void
    {
        $tool = new ConfigGetCapabilityDouble;

        $raw = $tool->execute(['key' => 'app.name']);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('FORBIDDEN', $envelope['error']['code']);
    }

    public function test_meta_has_count_zero_when_key_not_set(): void
    {
        $raw = $this->tool->execute(['key' => 'app.totally_nonexistent_setting_xyz']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame(0, $envelope['meta']['count']);
        $this->assertSame(0, $envelope['meta']['total']);
        $this->assertFalse($envelope['meta']['truncated']);
    }

    public function test_meta_has_count_one_when_key_is_found(): void
    {
        config(['services.phpclaw_test.name' => 'phpClaw']);

        $raw = $this->tool->execute(['key' => 'services.phpclaw_test.name']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame(1, $envelope['meta']['count']);
        $this->assertSame(1, $envelope['meta']['total']);
        $this->assertFalse($envelope['meta']['truncated']);
    }
}

final class ConfigGetCapabilityDouble extends AbstractLaravelTool
{
    public function name(): string
    {
        return 'config_get_capability_double';
    }

    public function description(): string
    {
        return 'Test double for the ConfigGetTool capability guard.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['key' => ['type' => 'string']]];
    }

    public function requiredCapability(): string
    {
        return LaravelIdentityResolver::CHAT_ABILITY;
    }

    protected function runningInConsole(): bool
    {
        return false;
    }

    protected function plan(array $input): array
    {
        $forbidden = $this->guardCapability('read a configuration value');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => null];
    }

    protected function perform(array $input): array
    {
        return ['key' => (string) ($input['key'] ?? ''), 'value' => null];
    }

    protected function verify(array $execution, array $input): array
    {
        return ['result' => null];
    }

    protected function complete(array $execution, array $input): string
    {
        return $this->success(
            ['key' => $execution['key'], 'value' => $execution['value']],
            ['mode' => 'query', 'count' => 0, 'total' => 0, 'truncated' => false],
        );
    }
}
