<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Tools;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Tools\AbstractLaravelTool;
use PhpClaw\Laravel\Tools\CacheInspectTool;

final class CacheInspectToolTest extends TestCase
{
    private CacheInspectTool $tool;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.memory_driver', 'array');
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new CacheInspectTool;
    }

    public function test_a_cache_driver_failure_is_retried_once_then_surfaces(): void
    {
        Cache::shouldReceive('has')
            ->times(2)
            ->andThrow(new \RuntimeException('cache backend unreachable'));

        $this->expectException(ToolException::class);

        $this->tool->execute(['key' => 'anything']);
    }

    public function test_name_returns_cache_inspect(): void
    {
        $this->assertSame('cache_inspect', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $description = $this->tool->description();

        $this->assertStringContainsString('INSPECT the Laravel cache', $description);
        $this->assertStringContainsString('never flushes or modifies cache entries', $description);
    }

    public function test_input_schema_is_valid(): void
    {
        $schema = $this->tool->inputSchema();

        $this->assertSame('object', $schema['type']);
    }

    public function test_required_capability_returns_chat_ability(): void
    {
        $this->assertSame('phpclaw.chat', $this->tool->requiredCapability());
    }

    public function test_success_envelope_has_required_keys(): void
    {
        $raw = $this->tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertIsArray($envelope);
        $this->assertTrue($envelope['success']);
        $this->assertArrayHasKey('data', $envelope);
        $this->assertArrayHasKey('meta', $envelope);
        $this->assertArrayHasKey('warnings', $envelope);
    }

    public function test_execute_without_key_returns_store_info(): void
    {
        $raw = $this->tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame('array', $envelope['data']['store']);
        $this->assertArrayNotHasKey('key', $envelope['data']);
        $this->assertArrayNotHasKey('exists', $envelope['data']);
        $this->assertSame('query', $envelope['meta']['mode']);
        $this->assertSame(0, $envelope['meta']['count']);
        $this->assertSame(0, $envelope['meta']['total']);
        $this->assertFalse($envelope['meta']['truncated']);
    }

    public function test_execute_with_missing_key_returns_success_with_not_found_warning(): void
    {
        $raw = $this->tool->execute(['key' => 'nonexistent_test_key_xyz']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertFalse($envelope['data']['exists']);
        $this->assertNull($envelope['data']['preview']);
        $this->assertSame(0, $envelope['meta']['count']);

        $codes = array_column($envelope['warnings'], 'code');
        $this->assertContains('NOT_FOUND', $codes);
    }

    public function test_execute_with_existing_key_returns_exists_true_and_preview(): void
    {
        Cache::put('phpclaw_test_key', 'hello-value', 60);

        $raw = $this->tool->execute(['key' => 'phpclaw_test_key']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertTrue($envelope['data']['exists']);
        $this->assertStringContainsString('hello-value', (string) $envelope['data']['preview']);
        $this->assertSame(1, $envelope['meta']['count']);
    }

    public function test_execute_truncates_large_value_preview(): void
    {
        Cache::put('phpclaw_large_key', str_repeat('x', 500), 60);

        $raw = $this->tool->execute(['key' => 'phpclaw_large_key']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertTrue($envelope['meta']['truncated']);

        $codes = array_column($envelope['warnings'], 'code');
        $this->assertContains('OUTPUT_TRUNCATED', $codes);

        $preview = (string) $envelope['data']['preview'];
        $this->assertLessThanOrEqual(256, strlen($preview));
    }

    public function test_execute_never_mutates_cache(): void
    {
        Cache::put('phpclaw_immutable_key', 'original', 60);

        $this->tool->execute(['key' => 'phpclaw_immutable_key']);

        $this->assertSame('original', Cache::get('phpclaw_immutable_key'));
    }

    public function test_execute_masks_preview_for_secret_named_key(): void
    {
        Cache::put('user_session_token', 'sk-abcdef1234567890', 60);

        $raw = $this->tool->execute(['key' => 'user_session_token']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame('[redacted]', $envelope['data']['preview']);
    }

    public function test_execute_masks_preview_for_crypto_key_named_entry(): void
    {
        Cache::put('app_encryption_key', 'base64:deadbeefdeadbeef', 60);

        $raw = $this->tool->execute(['key' => 'app_encryption_key']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame('[redacted]', $envelope['data']['preview']);
    }

    public function test_execute_keeps_preview_for_generic_key_named_entry(): void
    {
        Cache::put('phpclaw_report_key', 'quarterly-summary', 60);

        $raw = $this->tool->execute(['key' => 'phpclaw_report_key']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertStringContainsString('quarterly-summary', (string) $envelope['data']['preview']);
    }

    public function test_execute_scrubs_secret_patterns_from_preview_value(): void
    {
        Cache::put('phpclaw_meta_key', 'user=bob Bearer sk-abcdef1234567890', 60);

        $raw = $this->tool->execute(['key' => 'phpclaw_meta_key']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertStringNotContainsString('sk-abcdef1234567890', (string) $envelope['data']['preview']);
        $this->assertStringContainsString('[redacted]', (string) $envelope['data']['preview']);
    }

    public function test_execute_scrubs_secrets_from_a_json_encoded_preview_value(): void
    {
        Cache::put('phpclaw_meta_key', ['user' => 'bob', 'api_token' => 'live_9fabc123'], 60);

        $raw = $this->tool->execute(['key' => 'phpclaw_meta_key']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertStringNotContainsString('live_9fabc123', (string) $envelope['data']['preview']);
        $this->assertStringContainsString('[redacted]', (string) $envelope['data']['preview']);
        $this->assertStringContainsString('bob', (string) $envelope['data']['preview']);
    }

    public function test_execute_returns_error_for_unknown_argument(): void
    {
        $raw = $this->tool->execute(['unknown_param' => 'value']);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('UNKNOWN_ARGUMENT', $envelope['error']['code']);
    }

    public function test_execute_returns_error_when_key_is_not_a_string(): void
    {
        $raw = $this->tool->execute(['key' => 42]);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
    }

    public function test_capability_guard_returns_forbidden_when_no_authenticated_user_and_gate_undefined(): void
    {
        $tool = new CacheInspectCapabilityDouble;

        $raw = $tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('FORBIDDEN', $envelope['error']['code']);
    }

    public function test_capability_guard_returns_forbidden_when_gate_explicitly_denies(): void
    {
        $this->actingAs(new User);

        Gate::define('phpclaw.chat', fn () => false);

        $tool = new CacheInspectCapabilityDouble;

        $raw = $tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('FORBIDDEN', $envelope['error']['code']);
    }

    public function test_capability_guard_allows_authenticated_user_without_gate_defined(): void
    {
        $this->actingAs(new User);

        $raw = $this->tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame('array', $envelope['data']['store']);
    }
}

final class CacheInspectCapabilityDouble extends AbstractLaravelTool
{
    public function name(): string
    {
        return 'cache_inspect_capability_double';
    }

    public function description(): string
    {
        return 'Test double for the CacheInspectTool capability guard.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
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
        $forbidden = $this->guardCapability('inspect the cache');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => null];
    }

    protected function perform(array $input): array
    {
        return ['store' => 'array'];
    }

    protected function verify(array $execution, array $input): array
    {
        if (! is_string($execution['store'])) {
            throw new ToolException('capability_double: invalid store.');
        }

        return ['result' => null];
    }

    protected function complete(array $execution, array $input): string
    {
        return $this->success(
            ['store' => $execution['store']],
            ['mode' => 'query', 'count' => 0, 'total' => 0, 'truncated' => false],
        );
    }
}
