<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Hooks;

use PhpClaw\Hooks\Contracts\HookInterface;
use PhpClaw\Hooks\SecurityAlertHook;
use PHPUnit\Framework\TestCase;

final class SecurityAlertHookTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_ENV['PHPCLAW_SECURITY_WEBHOOK']);
        putenv('PHPCLAW_SECURITY_WEBHOOK');
    }

    public function test_implements_hook_interface(): void
    {
        $this->assertInstanceOf(HookInterface::class, new SecurityAlertHook);
    }

    public function test_webhook_url_accessor_returns_constructor_value(): void
    {
        $hook = new SecurityAlertHook(webhookUrl: 'https://hooks.example/abc');
        $this->assertSame('https://hooks.example/abc', $hook->webhookUrl());
    }

    public function test_ignores_non_guard_events(): void
    {
        $hook = new SecurityAlertHook(webhookUrl: 'http://127.0.0.1:1/nonexistent');

        $hook->handle(['event' => 'agent.before']);
        $hook->handle(['event' => 'tool.after']);
        $hook->handle(['event' => 'provider.request']);
        $hook->handle(['event' => '']);

        $this->addToAssertionCount(4);
    }

    public function test_handles_guard_blocked_event_without_webhook(): void
    {
        $hook = new SecurityAlertHook(webhookUrl: '');

        $hook->handle([
            'event' => 'guard.blocked',
            'guard' => 'InjectionGuard',
            'reason' => 'injection attempt',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_handles_guard_blocked_event_with_missing_payload(): void
    {
        $hook = new SecurityAlertHook(webhookUrl: '');

        $hook->handle(['event' => 'guard.blocked']);

        $this->addToAssertionCount(1);
    }

    public function test_never_throws_on_unreachable_webhook(): void
    {
        $hook = new SecurityAlertHook(webhookUrl: 'http://127.0.0.1:1/nonexistent');

        $hook->handle([
            'event' => 'guard.blocked',
            'guard' => 'InjectionGuard',
            'reason' => 'test',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_reads_webhook_from_env_when_constructor_empty(): void
    {
        putenv('PHPCLAW_SECURITY_WEBHOOK=http://127.0.0.1:1/envwebhook');

        $hook = new SecurityAlertHook(webhookUrl: '');
        $hook->handle([
            'event' => 'guard.blocked',
            'guard' => 'InjectionGuard',
            'reason' => 'env test',
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_empty_context_does_not_trigger_alert(): void
    {
        $hook = new SecurityAlertHook(webhookUrl: 'http://127.0.0.1:1/nonexistent');

        $hook->handle([]);

        $this->addToAssertionCount(1);
    }

    public function test_handle_blocks_ssrf_loopback_and_logs_error(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpclaw_hook_');
        ini_set('error_log', $logFile);

        $hook = new SecurityAlertHook(webhookUrl: 'https://127.0.0.1/hook');
        $hook->handle(['event' => 'guard.blocked', 'guard' => 'InjectionGuard', 'reason' => 'test']);

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('private address', $log);
    }

    public function test_handle_blocks_ssrf_localhost(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpclaw_hook_');
        ini_set('error_log', $logFile);

        $hook = new SecurityAlertHook(webhookUrl: 'https://localhost/hook');
        $hook->handle(['event' => 'guard.blocked']);

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('private address', $log);
    }

    public function test_handle_blocks_ssrf_private_class_a(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpclaw_hook_');
        ini_set('error_log', $logFile);

        $hook = new SecurityAlertHook(webhookUrl: 'https://10.0.0.1/hook');
        $hook->handle(['event' => 'guard.blocked']);

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('private address', $log);
    }

    public function test_handle_blocks_ssrf_private_class_b(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpclaw_hook_');
        ini_set('error_log', $logFile);

        $hook = new SecurityAlertHook(webhookUrl: 'https://172.16.0.1/hook');
        $hook->handle(['event' => 'guard.blocked']);

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('private address', $log);
    }

    public function test_handle_blocks_ssrf_private_class_c(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpclaw_hook_');
        ini_set('error_log', $logFile);

        $hook = new SecurityAlertHook(webhookUrl: 'https://192.168.1.1/hook');
        $hook->handle(['event' => 'guard.blocked']);

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('private address', $log);
    }

    public function test_handle_blocks_ssrf_link_local(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpclaw_hook_');
        ini_set('error_log', $logFile);

        $hook = new SecurityAlertHook(webhookUrl: 'https://169.254.169.254/latest/meta-data/');
        $hook->handle(['event' => 'guard.blocked']);

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('private address', $log);
    }

    public function test_handle_blocks_ssrf_ipv6_loopback(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpclaw_hook_');
        ini_set('error_log', $logFile);

        $hook = new SecurityAlertHook(webhookUrl: 'https://[::1]/hook');
        $hook->handle(['event' => 'guard.blocked']);

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('private address', $log);
    }

    public function test_custom_timeout_is_accepted_without_error(): void
    {
        $hook = new SecurityAlertHook(
            webhookUrl: 'https://hooks.example.com/alert',
            timeout: 10,
        );

        $this->assertSame('https://hooks.example.com/alert', $hook->webhookUrl());
    }

    public function test_handle_logs_error_for_http_not_https(): void
    {
        $logFile = tempnam(sys_get_temp_dir(), 'phpclaw_hook_');
        ini_set('error_log', $logFile);

        $hook = new SecurityAlertHook(webhookUrl: 'http://hooks.example.com/alert');
        $hook->handle(['event' => 'guard.blocked']);

        $log = (string) file_get_contents($logFile);
        unlink($logFile);

        $this->assertStringContainsString('HTTPS', $log);
    }

    public function test_handle_posts_url_and_payload_to_sendwebhook(): void
    {
        $hook = new class('https://1.1.1.1/alert') extends SecurityAlertHook
        {
            public string $capturedUrl = '';

            public string $capturedBody = '';

            protected function sendWebhook(string $url, string $body): void
            {
                $this->capturedUrl = $url;
                $this->capturedBody = $body;
            }
        };

        $hook->handle([
            'event' => 'guard.blocked',
            'guard' => 'InjectionGuard',
            'reason' => "Pattern matched: 'ignore previous instructions'",
        ]);

        $this->assertSame('https://1.1.1.1/alert', $hook->capturedUrl);

        $decoded = json_decode($hook->capturedBody, true);
        $this->assertIsArray($decoded);
        $this->assertSame('phpClaw: Attack blocked', $decoded['alert']);
        $this->assertSame('InjectionGuard', $decoded['guard']);
        $this->assertSame("Pattern matched: 'ignore previous instructions'", $decoded['reason']);
        $this->assertArrayHasKey('at', $decoded);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $decoded['at']);
    }

    public function test_handle_defaults_unknown_guard_and_reason_when_payload_empty(): void
    {
        $hook = new class('https://1.1.1.1/alert') extends SecurityAlertHook
        {
            public string $capturedBody = '';

            protected function sendWebhook(string $url, string $body): void
            {
                $this->capturedBody = $body;
            }
        };

        $hook->handle(['event' => 'guard.blocked']);

        $decoded = json_decode($hook->capturedBody, true);
        $this->assertSame('unknown', $decoded['guard']);
        $this->assertSame('unknown', $decoded['reason']);
    }

    public function test_sendwebhook_body_executes_without_throwing_on_unreachable_url(): void
    {
        $hook = new SecurityAlertHook(
            webhookUrl: 'https://192.0.2.1/unreachable',
            timeout: 1,
        );

        $ref = new \ReflectionClass($hook);
        $method = $ref->getMethod('sendWebhook');

        @$method->invoke($hook, 'https://192.0.2.1/unreachable', '{"test":"payload"}');

        $this->expectNotToPerformAssertions();
    }

    public function test_events_accessor_returns_default(): void
    {
        $hook = new SecurityAlertHook(webhookUrl: 'https://hooks.example/abc');

        $this->assertSame(['guard.blocked'], $hook->events());
    }

    public function test_timeout_accessor_returns_constructor_value(): void
    {
        $hook = new SecurityAlertHook(webhookUrl: 'https://hooks.example/abc', timeout: 7);

        $this->assertSame(7, $hook->timeout());
    }

    public function test_custom_events_array_filters_handled_events(): void
    {
        $hook = new class('https://1.1.1.1/alert', 3, ['guard.warned', 'agent.error']) extends SecurityAlertHook
        {
            public int $sendCount = 0;

            protected function sendWebhook(string $url, string $body): void
            {
                $this->sendCount++;
            }
        };

        $hook->handle(['event' => 'guard.blocked']);
        $this->assertSame(0, $hook->sendCount);

        $hook->handle(['event' => 'guard.warned', 'guard' => 'X', 'reason' => 'Y']);
        $this->assertSame(1, $hook->sendCount);

        $hook->handle(['event' => 'agent.error', 'guard' => 'X', 'reason' => 'Z']);
        $this->assertSame(2, $hook->sendCount);
    }

    public function test_custom_payload_formatter_replaces_default_shape(): void
    {
        $formatter = static fn (string $event, array $payload, string $iso): array => [
            'text' => "Custom alert: {$event}",
            'fields' => $payload,
        ];

        $hook = new class('https://1.1.1.1/alert', 3, ['guard.blocked'], $formatter) extends SecurityAlertHook
        {
            public string $capturedBody = '';

            protected function sendWebhook(string $url, string $body): void
            {
                $this->capturedBody = $body;
            }
        };

        $hook->handle([
            'event' => 'guard.blocked',
            'guard' => 'InjectionGuard',
            'reason' => 'pattern match',
        ]);

        $decoded = json_decode($hook->capturedBody, true);
        $this->assertSame('Custom alert: guard.blocked', $decoded['text']);
        $this->assertSame('InjectionGuard', $decoded['fields']['guard']);
        $this->assertSame('pattern match', $decoded['fields']['reason']);
    }

    public function test_payload_formatter_receives_iso_date(): void
    {
        $capturedIso = '';
        $formatter = static function (string $event, array $payload, string $iso) use (&$capturedIso): array {
            $capturedIso = $iso;

            return ['ok' => true];
        };

        $hook = new class('https://1.1.1.1/alert', 3, ['guard.blocked'], $formatter) extends SecurityAlertHook
        {
            protected function sendWebhook(string $url, string $body): void {}
        };

        $hook->handle(['event' => 'guard.blocked']);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $capturedIso);
    }

    public function test_webhook_context_disables_follow_location_and_max_redirects(): void
    {
        $hook = new SecurityAlertHook(webhookUrl: 'https://hooks.example.com/alert', timeout: 5);
        $ref = new \ReflectionClass($hook);
        $ctx = $ref->getMethod('buildWebhookContext')->invoke($hook, '{"guard":"test"}');

        $this->assertSame(0, $ctx['http']['follow_location']);
        $this->assertSame(0, $ctx['http']['max_redirects']);

        $this->assertSame('POST', $ctx['http']['method']);
        $this->assertSame('{"guard":"test"}', $ctx['http']['content']);
        $this->assertSame(5, $ctx['http']['timeout']);
        $this->assertTrue($ctx['http']['ignore_errors']);
    }
}
