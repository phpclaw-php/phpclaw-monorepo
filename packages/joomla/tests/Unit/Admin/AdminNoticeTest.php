<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Admin;

use PhpClaw\Joomla\Component\Administrator\Admin\AdminNotice;
use PHPUnit\Framework\TestCase;

final class AdminNoticeTest extends TestCase
{
    public function test_should_show_when_no_api_key_saved(): void
    {
        self::assertTrue(AdminNotice::shouldShow([]));
    }

    public function test_should_show_when_empty_api_key_saved(): void
    {
        self::assertTrue(AdminNotice::shouldShow(['api_key' => '']));
    }

    public function test_should_not_show_when_api_key_saved(): void
    {
        self::assertFalse(AdminNotice::shouldShow(['api_key' => 'sk-abc123']));
    }
}
