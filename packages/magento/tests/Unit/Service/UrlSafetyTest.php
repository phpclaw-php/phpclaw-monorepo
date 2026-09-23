<?php

declare(strict_types=1);

namespace PhpClaw\Magento\Tests\Unit\Service;

use PhpClaw\Magento\Service\UrlSafety;
use PHPUnit\Framework\TestCase;

final class UrlSafetyTest extends TestCase
{
    public function test_public_https_host_is_not_internal(): void
    {
        self::assertFalse(UrlSafety::isInternalUrl('https://api.example.com/v1'));
    }

    public function test_metadata_private_and_local_hosts_are_internal(): void
    {
        self::assertTrue(UrlSafety::isInternalUrl('http://169.254.169.254/latest'));
        self::assertTrue(UrlSafety::isInternalUrl('http://127.0.0.1:8000'));
        self::assertTrue(UrlSafety::isInternalUrl('http://10.0.0.5/x'));
        self::assertTrue(UrlSafety::isInternalUrl('http://192.168.1.10/x'));
        self::assertTrue(UrlSafety::isInternalUrl('https://localhost/x'));
        self::assertTrue(UrlSafety::isInternalUrl('https://svc.internal/x'));
        self::assertTrue(UrlSafety::isInternalUrl('not-a-url'));
    }
}
