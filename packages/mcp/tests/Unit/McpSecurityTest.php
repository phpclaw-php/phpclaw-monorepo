<?php

declare(strict_types=1);

namespace PhpClaw\Mcp\Tests\Unit;

use PhpClaw\Mcp\McpSecurity;
use PHPUnit\Framework\TestCase;

final class McpSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        McpSecurity::resetRateLimits();
    }

    public function test_validate_token_returns_true_for_matching_tokens(): void
    {
        self::assertTrue(McpSecurity::validateToken('secret-token', 'secret-token'));
    }

    public function test_validate_token_returns_false_for_mismatched_tokens(): void
    {
        self::assertFalse(McpSecurity::validateToken('wrong-token', 'secret-token'));
    }

    public function test_validate_token_returns_false_for_empty_provided(): void
    {
        self::assertFalse(McpSecurity::validateToken('', 'secret-token'));
    }

    public function test_validate_token_returns_false_for_empty_expected(): void
    {
        self::assertFalse(McpSecurity::validateToken('secret-token', ''));
    }

    public function test_validate_token_returns_false_for_both_empty(): void
    {
        self::assertFalse(McpSecurity::validateToken('', ''));
    }

    public function test_validate_token_is_case_sensitive(): void
    {
        self::assertFalse(McpSecurity::validateToken('Secret-Token', 'secret-token'));
    }

    public function test_rate_limit_allows_first_request(): void
    {
        self::assertTrue(McpSecurity::rateLimit('192.168.1.1'));
    }

    public function test_rate_limit_allows_requests_within_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue(McpSecurity::rateLimit('192.168.1.1', 10));
        }
    }

    public function test_rate_limit_blocks_requests_over_limit(): void
    {
        $ip = '10.0.0.1';

        for ($i = 0; $i < 3; $i++) {
            McpSecurity::rateLimit($ip, 3);
        }

        self::assertFalse(McpSecurity::rateLimit($ip, 3));
    }

    public function test_rate_limit_tracks_per_ip(): void
    {
        for ($i = 0; $i < 2; $i++) {
            McpSecurity::rateLimit('10.0.0.1', 2);
        }
        self::assertFalse(McpSecurity::rateLimit('10.0.0.1', 2));

        self::assertTrue(McpSecurity::rateLimit('10.0.0.2', 2));
    }

    public function test_get_request_count_returns_zero_for_unknown_ip(): void
    {
        self::assertSame(0, McpSecurity::getRequestCount('unknown'));
    }

    public function test_get_request_count_tracks_correctly(): void
    {
        McpSecurity::rateLimit('10.0.0.5', 100);
        McpSecurity::rateLimit('10.0.0.5', 100);
        McpSecurity::rateLimit('10.0.0.5', 100);

        self::assertSame(3, McpSecurity::getRequestCount('10.0.0.5'));
    }

    public function test_reset_rate_limits_clears_all(): void
    {
        McpSecurity::rateLimit('10.0.0.1');
        McpSecurity::resetRateLimits();

        self::assertSame(0, McpSecurity::getRequestCount('10.0.0.1'));
    }

    public function test_tool_allowed_with_empty_lists(): void
    {
        self::assertTrue(McpSecurity::isToolAllowed('any-tool'));
    }

    public function test_tool_allowed_when_in_allow_list(): void
    {
        self::assertTrue(McpSecurity::isToolAllowed('shell', allow: ['shell', 'http']));
    }

    public function test_tool_blocked_when_not_in_allow_list(): void
    {
        self::assertFalse(McpSecurity::isToolAllowed('file', allow: ['shell', 'http']));
    }

    public function test_tool_blocked_when_in_deny_list(): void
    {
        self::assertFalse(McpSecurity::isToolAllowed('shell', deny: ['shell']));
    }

    public function test_deny_wins_over_allow(): void
    {
        self::assertFalse(McpSecurity::isToolAllowed(
            'shell',
            allow: ['shell', 'http'],
            deny: ['shell'],
        ));
    }

    public function test_tool_allowed_when_not_in_deny_list_and_empty_allow(): void
    {
        self::assertTrue(McpSecurity::isToolAllowed('http', deny: ['shell']));
    }

    public function test_tool_allowed_when_in_allow_and_not_in_deny(): void
    {
        self::assertTrue(McpSecurity::isToolAllowed(
            'http',
            allow: ['shell', 'http'],
            deny: ['shell'],
        ));
    }

    public function test_apcu_backed_limiter_persists(): void
    {
        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            self::markTestSkipped('APCu not available.');
        }

        McpSecurity::resetRateLimits();
        McpSecurity::rateLimit('10.0.0.9', 5);
        McpSecurity::rateLimit('10.0.0.9', 5);
        McpSecurity::rateLimit('10.0.0.9', 5);

        self::assertSame(3, McpSecurity::getRequestCount('10.0.0.9'));
    }
}
