<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Support;

use PhpClaw\Exceptions\ToolException;
use PhpClaw\Support\SsrfValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SsrfValidatorTest extends TestCase
{
    public function test_public_host_passes(): void
    {
        SsrfValidator::assertPublicHost('https://example.com/path');
        $this->assertTrue(SsrfValidator::isPublicHost('https://example.com/path'));
    }

    public function test_empty_host_is_rejected(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('not-a-url');
    }

    #[DataProvider('privateUrlProvider')]
    public function test_private_addresses_are_blocked(string $url): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost($url);
    }

    public static function privateUrlProvider(): array
    {
        return [
            'localhost' => ['http://localhost/'],
            'ipv4 loopback' => ['http://127.0.0.1/'],
            'ipv4 10.x' => ['http://10.0.0.1/'],
            'ipv4 192.168' => ['http://192.168.1.1/'],
            'ipv4 link-local' => ['http://169.254.1.1/'],
            'ipv4 172.16/12' => ['http://172.16.0.1/'],
            'ipv4 172.31/12' => ['http://172.31.255.255/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'ipv6 ULA' => ['http://[fd00::1]/'],
            'ipv6 link-local' => ['http://[fe80::1]/'],
        ];
    }

    public function test_is_public_host_false_for_private_and_unparseable(): void
    {
        $this->assertFalse(SsrfValidator::isPublicHost('http://127.0.0.1/'));
        $this->assertFalse(SsrfValidator::isPublicHost('http://172.16.0.1/'));
        $this->assertFalse(SsrfValidator::isPublicHost('http://[::1]/'));
        $this->assertFalse(SsrfValidator::isPublicHost('not-a-url'));
    }

    public function test_invalid_ipv6_returns_silently(): void
    {
        $this->invokePrivate('assertResolvedIpv6', ['not-a-real-ipv6', 'host.example']);
        $this->assertTrue(true);
    }

    public function test_blocked_ipv4_defers_to_ipv6_when_not_ipv4(): void
    {
        $this->expectException(ToolException::class);
        $this->invokePrivate('assertBlockedIpv4', ['::1', 'host.example']);
    }

    public function test_blocked_ipv4_in_172_range_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        $this->invokePrivate('assertBlockedIpv4', ['172.20.10.5', 'host.example']);
    }

    public function test_c3_decimal_link_local_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://2852039166/');
    }

    public function test_c3_hex_link_local_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://0xA9FEA9FE/');
    }

    public function test_c3_dotted_hex_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://0xa9.0xfe.0xa9.0xfe/');
    }

    public function test_c3_dotted_octal_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://0251.0376.0251.0376/');
    }

    public function test_c3_decimal_loopback_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://2130706433/');
    }

    public function test_c3_hex_loopback_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://0x7f000001/');
    }

    public function test_c4a_dns_rebind_resolved_ip_is_blocked(): void
    {
        if (@gethostbynamel('sslip.io') === false) {
            $this->markTestSkipped('sslip.io unreachable in this environment: DNS-rebind bypass test skipped.');
        }
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://169-254-169-254.sslip.io/');
    }

    public function test_literal_link_local_still_blocked(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://169.254.169.254/');
    }

    public function test_public_ip_literal_not_blocked(): void
    {
        SsrfValidator::assertPublicHost('http://8.8.8.8/');
        $this->assertTrue(SsrfValidator::isPublicHost('http://8.8.8.8/'));
    }

    public function test_cgnat_literal_is_blocked(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://100.64.0.1/');
    }

    public function test_dns_failure_fails_closed(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://nonexistent-phpclawssrf99.invalid/');
    }

    public function test_all_hex_label_not_classified_as_numeric_encoding(): void
    {
        foreach (['http://cafe/', 'http://beef/'] as $url) {
            try {
                SsrfValidator::assertPublicHost($url);
                $this->fail("Expected ToolException for {$url} (fail-closed should throw on unresolvable host)");
            } catch (ToolException $e) {
                $this->assertStringNotContainsString(
                    'non-standard IP encoding',
                    $e->getMessage(),
                    "Part 1 must not misclassify all-hex-letter label as numeric encoding: {$url}"
                );
            }
        }
    }

    public function test_ipv4_mapped_ipv6_still_blocked(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::assertPublicHost('http://[::ffff:169.254.169.254]/');
    }

    public function test_resolve_validated_throws_for_blocked_link_local_ip_literal(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::resolveValidated('http://169.254.169.254/');
    }

    public function test_resolve_validated_throws_for_cgnat_ip_literal(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::resolveValidated('http://100.64.0.1/');
    }

    public function test_resolve_validated_passes_and_returns_literal_for_public_ip(): void
    {
        $result = SsrfValidator::resolveValidated('http://8.8.8.8/');
        $this->assertSame('8.8.8.8', $result['host']);
        $this->assertSame(80, $result['port']);
        $this->assertSame(['8.8.8.8'], $result['ips']);
    }

    public function test_resolve_validated_port_defaults_to_443_for_https(): void
    {
        $result = SsrfValidator::resolveValidated('https://8.8.8.8/');
        $this->assertSame(443, $result['port']);
    }

    public function test_resolve_validated_uses_explicit_port_when_present(): void
    {
        $result = SsrfValidator::resolveValidated('https://8.8.8.8:8443/path');
        $this->assertSame(8443, $result['port']);
    }

    public function test_resolve_validated_returns_ips_for_resolvable_public_host(): void
    {
        if (@gethostbynamel('one.one.one.one') === false) {
            $this->markTestSkipped('DNS unavailable: resolveValidated hostname test skipped.');
        }
        $result = SsrfValidator::resolveValidated('https://one.one.one.one/');
        $this->assertSame('one.one.one.one', $result['host']);
        $this->assertSame(443, $result['port']);
        $this->assertNotEmpty($result['ips']);
        foreach ($result['ips'] as $ip) {
            $this->assertTrue(filter_var($ip, FILTER_VALIDATE_IP) !== false);
        }
    }

    public function test_resolve_validated_fails_closed_for_invalid_host(): void
    {
        $this->expectException(ToolException::class);
        SsrfValidator::resolveValidated('http://nonexistent-phpclawssrf99.invalid/');
    }

    public function test_pin_entry_hostname_returns_host_port_ips_string(): void
    {
        $result = SsrfValidator::pinEntry(['host' => 'example.com', 'port' => 443, 'ips' => ['93.184.216.34']]);
        $this->assertSame('example.com:443:93.184.216.34', $result);
    }

    public function test_pin_entry_multiple_ips_comma_joined(): void
    {
        $result = SsrfValidator::pinEntry([
            'host' => 'example.com',
            'port' => 443,
            'ips' => ['93.184.216.34', '2001:500:200::a'],
        ]);
        $this->assertSame('example.com:443:93.184.216.34,2001:500:200::a', $result);
    }

    public function test_pin_entry_ipv4_literal_returns_null(): void
    {
        $result = SsrfValidator::pinEntry(['host' => '8.8.8.8', 'port' => 80, 'ips' => ['8.8.8.8']]);
        $this->assertNull($result);
    }

    public function test_pin_entry_ipv6_literal_returns_null(): void
    {
        $result = SsrfValidator::pinEntry(['host' => '2001:4860:4860::8888', 'port' => 443, 'ips' => ['2001:4860:4860::8888']]);
        $this->assertNull($result);
    }

    private function invokePrivate(string $method, array $args): void
    {
        (new \ReflectionMethod(SsrfValidator::class, $method))->invoke(null, ...$args);
    }
}
