<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Queue;

use PhpClaw\Exceptions\AdapterException;
use PhpClaw\Memory\ArrayMemory;
use PhpClaw\Symfony\Queue\QueueManager;
use PhpClaw\Symfony\Queue\RunAgentMessage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class QueueManagerTest extends TestCase
{
    public function test_poll_result_returns_null_for_unknown(): void
    {
        $q = new QueueManager(new ArrayMemory);

        self::assertNull($q->pollResult('nope'));
    }

    public function test_poll_result_returns_stored_array(): void
    {
        $mem = new ArrayMemory;
        $mem->set('j1', ['status' => 'done', 'text' => 'ok'], RunAgentMessage::NAMESPACE);

        self::assertSame(['status' => 'done', 'text' => 'ok'], (new QueueManager($mem))->pollResult('j1'));
    }

    public function test_list_jobs_filters_non_arrays(): void
    {
        $mem = new ArrayMemory;
        $mem->set('a', ['status' => 'done'], RunAgentMessage::NAMESPACE);
        $mem->set('b', 'corrupt', RunAgentMessage::NAMESPACE);

        $jobs = (new QueueManager($mem))->listJobs();

        self::assertArrayHasKey('a', $jobs);
        self::assertArrayNotHasKey('b', $jobs);
    }

    public function test_forget_job(): void
    {
        $mem = new ArrayMemory;
        $mem->set('j1', ['status' => 'done'], RunAgentMessage::NAMESPACE);

        (new QueueManager($mem))->forgetJob('j1');

        self::assertNull($mem->get('j1', RunAgentMessage::NAMESPACE));
    }

    public function test_dispatch_throws_without_messenger(): void
    {
        $q = new QueueManager(new ArrayMemory, bus: null);

        $this->expectException(AdapterException::class);
        $this->expectExceptionMessageMatches('/symfony\/messenger/i');
        $q->dispatchSend('hi');
    }

    public function test_messenger_installed_check(): void
    {
        self::assertSame(
            interface_exists(MessageBusInterface::class),
            QueueManager::messengerInstalled(),
        );
    }

    public function test_dispatch_returns_job_id_when_bus_present(): void
    {
        if (! interface_exists(MessageBusInterface::class)) {
            $this->markTestSkipped('symfony/messenger not installed');
        }

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->willReturn(new Envelope(new \stdClass));

        $q = new QueueManager(new ArrayMemory, bus: $bus);
        $jobId = $q->dispatchSend('hello', 60);

        self::assertNotEmpty($jobId);
        self::assertIsString($jobId);
    }
}
