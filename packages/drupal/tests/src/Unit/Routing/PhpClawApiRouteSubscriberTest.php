<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Routing;

use PhpClaw\Drupal\Routing\PhpClawApiRouteSubscriber;
use PHPUnit\Framework\TestCase;

final class PhpClawApiRouteSubscriberTest extends TestCase
{
    public function test_the_shipped_default_is_basic_auth_and_cookie(): void
    {
        self::assertSame(['basic_auth', 'cookie'], PhpClawApiRouteSubscriber::DEFAULT_PROVIDERS);
    }

    public function test_a_stored_list_is_returned_as_is(): void
    {
        self::assertSame(
            ['basic_auth', 'cookie', 'oauth2'],
            PhpClawApiRouteSubscriber::normalise(['basic_auth', 'cookie', 'oauth2']),
        );
    }

    public function test_a_site_can_add_its_own_provider(): void
    {
        self::assertContains('oauth2', PhpClawApiRouteSubscriber::normalise(['cookie', 'oauth2']));
    }

    public function test_an_empty_list_falls_back_to_the_default(): void
    {
        self::assertSame(PhpClawApiRouteSubscriber::DEFAULT_PROVIDERS, PhpClawApiRouteSubscriber::normalise([]));
    }

    public function test_a_null_setting_falls_back_to_the_default(): void
    {
        self::assertSame(PhpClawApiRouteSubscriber::DEFAULT_PROVIDERS, PhpClawApiRouteSubscriber::normalise(null));
    }

    public function test_a_comma_separated_string_is_split(): void
    {
        self::assertSame(['basic_auth', 'cookie'], PhpClawApiRouteSubscriber::normalise('basic_auth, cookie'));
    }

    public function test_whitespace_around_ids_is_trimmed(): void
    {
        self::assertSame(['basic_auth', 'cookie'], PhpClawApiRouteSubscriber::normalise(['  basic_auth ', "cookie\n"]));
    }

    public function test_blank_entries_are_dropped(): void
    {
        self::assertSame(['cookie'], PhpClawApiRouteSubscriber::normalise(['', '   ', 'cookie']));
    }

    public function test_duplicate_ids_are_collapsed(): void
    {
        self::assertSame(['cookie'], PhpClawApiRouteSubscriber::normalise(['cookie', 'cookie']));
    }

    public function test_a_list_of_only_blanks_falls_back_to_the_default(): void
    {
        self::assertSame(PhpClawApiRouteSubscriber::DEFAULT_PROVIDERS, PhpClawApiRouteSubscriber::normalise(['', ' ']));
    }
}
