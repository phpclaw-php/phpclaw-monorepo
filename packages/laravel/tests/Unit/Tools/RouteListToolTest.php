<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Unit\Tools;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PhpClaw\Exceptions\ToolException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\PhpClawServiceProvider;
use PhpClaw\Laravel\Tools\AbstractLaravelTool;
use PhpClaw\Laravel\Tools\RouteListTool;

final class RouteListToolTest extends TestCase
{
    private RouteListTool $tool;

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
        $this->tool = new RouteListTool;
    }

    public function test_name_returns_route_list(): void
    {
        $this->assertSame('route_list', $this->tool->name());
    }

    public function test_description_states_what_the_tool_does(): void
    {
        $description = $this->tool->description();

        $this->assertStringContainsString('LIST all registered Laravel routes', $description);
        $this->assertStringContainsString('never mutates routes', $description);
    }

    public function test_input_schema_is_valid_object(): void
    {
        $schema = $this->tool->inputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
    }

    public function test_required_capability_returns_chat_ability(): void
    {
        $this->assertSame('phpclaw.chat', $this->tool->requiredCapability());
    }

    public function test_execute_returns_success_envelope_with_registered_routes(): void
    {
        Route::get('/test-phpclaw-route', fn () => 'ok')->name('phpclaw.test');

        $raw = $this->tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertArrayHasKey('data', $envelope);
        $this->assertArrayHasKey('meta', $envelope);
        $this->assertArrayHasKey('warnings', $envelope);

        $uris = array_column($envelope['data']['routes'], 'uri');
        $this->assertContains('test-phpclaw-route', $uris);
    }

    public function test_execute_envelope_meta_has_expected_keys(): void
    {
        Route::get('/meta-check-route', fn () => 'ok');

        $raw = $this->tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertArrayHasKey('mode', $envelope['meta']);
        $this->assertArrayHasKey('count', $envelope['meta']);
        $this->assertArrayHasKey('total', $envelope['meta']);
        $this->assertArrayHasKey('truncated', $envelope['meta']);
        $this->assertSame('query', $envelope['meta']['mode']);
    }

    public function test_execute_filter_returns_only_matching_routes(): void
    {
        Route::get('/unique-test-endpoint', fn () => 'ok');
        Route::get('/other-endpoint', fn () => 'ok');

        $raw = $this->tool->execute(['filter' => 'unique-test']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $uris = array_column($envelope['data']['routes'], 'uri');
        $this->assertContains('unique-test-endpoint', $uris);
        $this->assertNotContains('other-endpoint', $uris);
    }

    public function test_execute_filter_no_match_returns_success_with_not_found_warning(): void
    {
        $raw = $this->tool->execute(['filter' => 'this-route-does-not-exist-xyz']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertSame([], $envelope['data']['routes']);
        $this->assertSame(0, $envelope['meta']['count']);

        $codes = array_column($envelope['warnings'], 'code');
        $this->assertContains('NOT_FOUND', $codes);
    }

    public function test_execute_result_contains_method_uri_and_action_fields(): void
    {
        Route::get('/inspect-route', fn () => 'ok');

        $raw = $this->tool->execute(['filter' => 'inspect-route']);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);
        $this->assertNotEmpty($envelope['data']['routes']);

        $first = $envelope['data']['routes'][0];
        $this->assertArrayHasKey('methods', $first);
        $this->assertArrayHasKey('uri', $first);
        $this->assertArrayHasKey('action', $first);
    }

    public function test_execute_returns_error_envelope_for_invalid_filter_type(): void
    {
        $raw = $this->tool->execute(['filter' => 123]);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('INVALID_ARGUMENT', $envelope['error']['code']);
    }

    public function test_execute_returns_error_envelope_for_unknown_argument(): void
    {
        $raw = $this->tool->execute(['unknown_key' => 'value']);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('UNKNOWN_ARGUMENT', $envelope['error']['code']);
    }

    public function test_capability_guard_returns_forbidden_when_no_authenticated_user_and_gate_undefined(): void
    {
        $tool = new RouteListCapabilityDouble;

        $raw = $tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('FORBIDDEN', $envelope['error']['code']);
    }

    public function test_capability_guard_returns_forbidden_when_gate_explicitly_denies(): void
    {
        $this->actingAs(new User);

        Gate::define('phpclaw.chat', fn () => false);

        $tool = new RouteListCapabilityDouble;

        $raw = $tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertFalse($envelope['success']);
        $this->assertSame('FORBIDDEN', $envelope['error']['code']);
    }

    public function test_capability_guard_allows_authenticated_user_without_gate_defined(): void
    {
        $this->actingAs(new User);

        Route::get('/auth-visible-route', fn () => 'ok');

        $raw = $this->tool->execute([]);
        $envelope = json_decode($raw, associative: true);

        $this->assertTrue($envelope['success']);

        $uris = array_column($envelope['data']['routes'], 'uri');
        $this->assertContains('auth-visible-route', $uris);
    }
}

final class RouteListCapabilityDouble extends AbstractLaravelTool
{
    public function name(): string
    {
        return 'route_list_capability_double';
    }

    public function description(): string
    {
        return 'Test double for the RouteListTool capability guard.';
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
        $forbidden = $this->guardCapability('list the registered routes');

        if ($forbidden !== null) {
            return ['input' => $input, 'result' => $forbidden];
        }

        return ['input' => $input, 'result' => null];
    }

    protected function perform(array $input): array
    {
        return ['routes' => []];
    }

    protected function verify(array $execution, array $input): array
    {
        if (! is_array($execution['routes'])) {
            throw new ToolException('capability_double: invalid routes.');
        }

        return ['result' => null];
    }

    protected function complete(array $execution, array $input): string
    {
        return $this->success(['routes' => $execution['routes']], ['mode' => 'query', 'count' => 0, 'total' => 0, 'truncated' => false]);
    }
}
