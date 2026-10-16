<?php

declare(strict_types=1);

namespace PhpClaw\Joomla\Tests\Unit\Model;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use PhpClaw\Joomla\Component\Administrator\Model\ChatModel;
use PHPUnit\Framework\TestCase;

final class ChatModelCountTest extends TestCase
{
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queries = [];

        $db = $this->createMock(DatabaseInterface::class);
        $db->method('quoteName')->willReturnCallback(static fn (string $name): string => '`'.$name.'`');
        $db->method('setQuery')->willReturnCallback(function ($query) use ($db) {
            $this->queries[] = (string) $query;

            return $db;
        });
        $db->method('loadResult')->willReturn(0);

        Factory::$container = new class($db)
        {
            public function __construct(private object $db) {}

            public function get(string $id): object
            {
                return $this->db;
            }
        };
    }

    protected function tearDown(): void
    {
        Factory::$container = null;
        Factory::$application = null;
        parent::tearDown();
    }

    public function test_the_conversation_count_reads_only_the_conversation_table(): void
    {
        [$conversationCount] = $this->countQueries(manageAll: false);

        self::assertStringContainsString('phpclaw_conversations', $conversationCount);
        self::assertStringNotContainsString('phpclaw_messages', $conversationCount);
        self::assertStringNotContainsString('JOIN', $conversationCount);
    }

    public function test_the_message_count_joins_through_the_conversation_table(): void
    {
        [, $messageCount] = $this->countQueries(manageAll: false);

        self::assertStringContainsString('INNER JOIN `#__phpclaw_conversations`', $messageCount);
        self::assertStringContainsString('`conversation_id`', $messageCount);
    }

    public function test_both_counts_are_scoped_to_the_acting_user_without_manage_all(): void
    {
        foreach ($this->countQueries(manageAll: false) as $query) {
            self::assertStringContainsString('`user_id` = 7', $query);
        }
    }

    public function test_manage_all_counts_every_user(): void
    {
        foreach ($this->countQueries(manageAll: true) as $query) {
            self::assertStringNotContainsString('user_id', $query);
        }
    }

    private function countQueries(bool $manageAll): array
    {
        $identity = new class($manageAll)
        {
            public int $id = 7;

            public function __construct(private bool $manageAll) {}

            public function authorise(string $action, ?string $asset = null): bool
            {
                return $this->manageAll;
            }
        };

        Factory::$application = new class($identity)
        {
            public function __construct(private object $identity) {}

            public function getIdentity(): object
            {
                return $this->identity;
            }
        };

        (new \ReflectionMethod(ChatModel::class, 'countConversationsAndMessages'))->invoke(new ChatModel);

        return $this->queries;
    }
}
