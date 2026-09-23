<?php

declare(strict_types=1);

namespace PhpClaw\Tests\Unit\Guards;

use PhpClaw\Exceptions\GuardException;
use PhpClaw\Guards\Contracts\GuardInterface;
use PhpClaw\Guards\PiiDetectionGuard;
use PHPUnit\Framework\TestCase;

final class PiiDetectionGuardTest extends TestCase
{
    public function test_implements_guard_interface(): void
    {
        $this->assertInstanceOf(GuardInterface::class, new PiiDetectionGuard);
    }

    public function test_scan_returns_void_for_safe_prompt(): void
    {
        $guard = new PiiDetectionGuard;
        $guard->scan('What is the weather today?');

        $this->addToAssertionCount(1);
    }

    public function test_scan_throws_guard_exception_on_email(): void
    {
        $this->expectException(GuardException::class);
        $this->expectExceptionMessage('PII detected: email found in prompt.');

        (new PiiDetectionGuard)->scan('my email is test@example.com');
    }

    public function test_scan_throws_guard_exception_on_credit_card_with_spaces(): void
    {
        $this->expectException(GuardException::class);
        $this->expectExceptionMessage('PII detected: credit_card found in prompt.');

        (new PiiDetectionGuard)->scan('my card is 4111 1111 1111 1111');
    }

    public function test_scan_throws_guard_exception_on_credit_card_with_dashes(): void
    {
        $this->expectException(GuardException::class);
        $this->expectExceptionMessage('PII detected: credit_card found in prompt.');

        (new PiiDetectionGuard)->scan('card: 4111-1111-1111-1111');
    }

    public function test_scan_throws_guard_exception_on_ssn(): void
    {
        $this->expectException(GuardException::class);
        $this->expectExceptionMessage('PII detected: ssn found in prompt.');

        (new PiiDetectionGuard)->scan('ssn: 123-45-6789');
    }

    public function test_scan_throws_guard_exception_on_phone_number(): void
    {
        $this->expectException(GuardException::class);
        $this->expectExceptionMessage('PII detected: phone found in prompt.');

        (new PiiDetectionGuard)->scan('call me at 555-123-4567');
    }

    public function test_scan_is_silent_when_block_on_detection_false(): void
    {
        $guard = new PiiDetectionGuard(blockOnDetection: false);

        $guard->scan('email: test@example.com, card: 4111 1111 1111 1111');

        $this->addToAssertionCount(1);
    }

    public function test_scan_allows_bare_numbers_that_are_not_pii(): void
    {
        $guard = new PiiDetectionGuard;
        $guard->scan('the answer is 42 and the room is 307');

        $this->addToAssertionCount(1);
    }

    public function test_scan_allows_domain_without_mailbox(): void
    {
        $guard = new PiiDetectionGuard;
        $guard->scan('visit example.com for more info');

        $this->addToAssertionCount(1);
    }

    public function test_exception_message_names_the_pii_type(): void
    {
        try {
            (new PiiDetectionGuard)->scan('write to billing@example.com about it');
            $this->fail('Expected GuardException');
        } catch (GuardException $e) {
            $this->assertStringContainsString('email', $e->getMessage());
            $this->assertStringContainsString('PII detected', $e->getMessage());
        }
    }

    public function test_scan_completes_quickly_on_long_email_like_string(): void
    {
        $guard = new PiiDetectionGuard;
        $input = str_repeat('a', 10000).'@'.str_repeat('b', 10000);

        $start = microtime(true);
        try {
            $guard->scan($input);
        } catch (GuardException) {
        }
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, 'Email regex took too long, possible ReDoS');
    }

    public function test_scan_completes_quickly_on_long_phone_like_string(): void
    {
        $guard = new PiiDetectionGuard;
        $input = str_repeat('555-555-', 6250);

        $start = microtime(true);
        try {
            $guard->scan($input);
        } catch (GuardException) {
        }
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, 'Phone regex took too long, possible ReDoS');
    }

    public function test_scan_completes_quickly_on_50kb_safe_message(): void
    {
        $guard = new PiiDetectionGuard;
        $input = str_repeat('This is a completely safe message with no personal information. ', 800);

        $start = microtime(true);
        $guard->scan($input);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, 'Full 50KB scan took too long, possible ReDoS');
    }

    public function test_detect_returns_all_matches(): void
    {
        $guard = new PiiDetectionGuard;
        $matches = $guard->detect('Email: foo@bar.com Phone: 555-555-5555');

        $this->assertContains('email', $matches);
        $this->assertContains('phone', $matches);
    }

    public function test_custom_patterns_can_be_added(): void
    {
        $guard = new PiiDetectionGuard(customPatterns: [
            'iban' => '/[A-Z]{2}\d{2}[A-Z0-9]{4,30}/',
        ]);

        $this->expectException(GuardException::class);
        $this->expectExceptionMessage('PII detected: iban found in prompt.');

        $guard->scan('My IBAN: GB29NWBK60161331926819');
    }

    public function test_passive_mode_detects_without_throwing(): void
    {
        $guard = new PiiDetectionGuard(blockOnDetection: false);

        $guard->scan('email me at foo@bar.com');
        $this->addToAssertionCount(1);

        $this->assertSame(['email'], $guard->detect('email me at foo@bar.com'));
    }

    public function test_custom_message_template(): void
    {
        $guard = new PiiDetectionGuard(
            messageTemplate: 'Blocked: {type} not allowed'
        );

        try {
            $guard->scan('foo@bar.com');
            $this->fail('Expected GuardException');
        } catch (GuardException $e) {
            $this->assertSame('Blocked: email not allowed', $e->getMessage());
        }
    }
}
