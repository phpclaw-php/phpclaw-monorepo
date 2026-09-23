<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Command;

use Doctrine\DBAL\Connection;
use PhpClaw\Symfony\Command\StatsCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class StatsCommandTest extends TestCase
{
    public function test_warns_when_not_doctrine_driver(): void
    {
        $tester = new CommandTester(new StatsCommand('file'));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Doctrine memory driver', $display);
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_warns_when_no_connection_available(): void
    {
        $tester = new CommandTester(new StatsCommand('doctrine', null));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Doctrine DBAL is not installed', $display);
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_returns_success_with_mock_connection(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn('0');

        $tester = new CommandTester(new StatsCommand('doctrine', $conn));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('Conversations', $display);
        self::assertStringContainsString('Messages', $display);
        self::assertStringContainsString('Active 24h', $display);
        self::assertSame(0, $tester->getStatusCode());
    }

    public function test_an_unreadable_count_is_reported_rather_than_shown_as_zero(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willThrowException(new \RuntimeException('table not found'));

        $tester = new CommandTester(new StatsCommand('doctrine', $conn));
        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('unavailable', $display);
        self::assertStringNotContainsString('Conversations:  0', $display);
        self::assertStringNotContainsString('table not found', $display);
    }
}
