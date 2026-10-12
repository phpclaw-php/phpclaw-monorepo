<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Tests\Unit\Install;

require_once __DIR__.'/../../../../phpclaw.install';

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Core\State\StateInterface;
use PhpClaw\Drupal\DrupalConsole;
use PhpClaw\Drupal\Tests\Unit\Fixtures\BootsDrupalContainer;
use PHPUnit\Framework\TestCase;

final class PhpClawUninstallTest extends TestCase
{
    use BootsDrupalContainer;

    public function test_uninstall_deletes_the_queue_and_the_last_console_conversation_id(): void
    {
        $queue = $this->createMock(QueueInterface::class);
        $queue->expects(self::once())->method('deleteQueue');
        $queueFactory = $this->createMock(QueueFactory::class);
        $queueFactory->method('get')->with('phpclaw_agent')->willReturn($queue);
        $state = $this->createMock(StateInterface::class);
        $state->expects(self::once())->method('delete')->with(DrupalConsole::LAST_CONVERSATION_STATE_KEY);
        $this->bootDrupalContainerWithUser(services: ['queue' => $queueFactory, 'state' => $state]);

        phpclaw_uninstall();
    }
}
