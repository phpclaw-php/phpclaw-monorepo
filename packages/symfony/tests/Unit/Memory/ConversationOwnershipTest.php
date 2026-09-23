<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Memory;

use Doctrine\DBAL\Connection;
use PhpClaw\Symfony\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Symfony\Memory\DoctrineConversationMemory;
use PhpClaw\Symfony\SymfonyIdentityResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ConversationOwnershipTest extends TestCase
{
    private Connection&MockObject $conn;

    protected function setUp(): void
    {
        $this->conn = $this->createMock(Connection::class);
    }

    private function identity(string $userId, bool $manageAll): SymfonyIdentityResolver
    {
        $user = $userId === '' ? null : new class($userId) implements UserInterface
        {
            public function __construct(private readonly string $id) {}

            public function getUserIdentifier(): string
            {
                return $this->id;
            }

            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }
        };

        $tokenStorage = new class($user) implements TokenStorageInterface
        {
            public function __construct(private readonly ?UserInterface $user) {}

            public function getToken(): ?TokenInterface
            {
                if ($this->user === null) {
                    return null;
                }

                return new class($this->user) implements TokenInterface
                {
                    public function __construct(private readonly UserInterface $user) {}

                    public function getUser(): ?UserInterface
                    {
                        return $this->user;
                    }
                };
            }
        };

        $checker = new class($manageAll) implements AuthorizationCheckerInterface
        {
            public function __construct(private readonly bool $granted) {}

            public function isGranted(mixed $attribute, mixed $subject = null): bool
            {
                return $attribute === SymfonyIdentityResolver::MANAGE_ALL_ROLE && $this->granted;
            }
        };

        return new SymfonyIdentityResolver($tokenStorage, $checker);
    }

    private function memory(string $userId, bool $manageAll = false): DoctrineConversationMemory
    {
        return new DoctrineConversationMemory($this->conn, false, $this->identity($userId, $manageAll));
    }

    public function test_insert_stamps_the_acting_user(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(false, 0);

        $captured = [];
        $this->conn->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured): int {
                if (str_contains($sql, 'INSERT INTO')) {
                    $captured = $params;
                }

                return 1;
            });

        $this->memory('alice@example.com')->set('c1', ['history' => []], 'conversations');

        self::assertSame('alice@example.com', $captured[2] ?? null);
    }

    public function test_set_never_overwrites_a_same_id_row_in_a_different_namespace(): void
    {
        $fetchOneCalls = [];
        $this->conn->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$fetchOneCalls): mixed {
                $fetchOneCalls[] = [$sql, $params];

                return false;
            });

        $executed = [];
        $this->conn->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$executed): int {
                $executed[] = [$sql, $params];

                return 1;
            });

        $this->memory('alice@example.com')->set('c1', ['history' => []], 'other-namespace');

        self::assertCount(2, $fetchOneCalls);
        foreach ($fetchOneCalls as [$sql, $params]) {
            self::assertStringContainsString('namespace', $sql, 'Every existence/ownership probe must scope by namespace, not id alone.');
            self::assertSame(['c1', 'other-namespace'], $params);
        }

        self::assertStringContainsString('INSERT INTO', $executed[0][0], 'A row absent at this (id, namespace) must INSERT, never UPDATE a different namespace row sharing the id.');
    }

    public function test_update_scopes_the_where_clause_by_namespace_too(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls('alice@example.com', 1);

        $updateSql = null;
        $this->conn->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$updateSql): int {
                if (str_contains($sql, 'UPDATE')) {
                    $updateSql = $sql;
                }

                return 1;
            });

        $this->memory('alice@example.com')->set('c1', ['history' => []], 'conversations');

        self::assertNotNull($updateSql);
        self::assertMatchesRegularExpression('/WHERE id = \? AND namespace = \?/', $updateSql);
        self::assertStringNotContainsString('SET namespace', $updateSql, 'namespace must never be reassigned by an UPDATE.');
    }

    public function test_update_never_rewrites_the_owner(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls('alice@example.com', 1);

        $sqls = [];
        $this->conn->method('executeStatement')
            ->willReturnCallback(function (string $sql) use (&$sqls): int {
                $sqls[] = $sql;

                return 1;
            });

        $this->memory('alice@example.com')->set('c1', ['history' => []], 'conversations');

        $updates = array_values(array_filter($sqls, static fn (string $q): bool => str_contains($q, 'UPDATE')));
        self::assertNotSame([], $updates);
        foreach ($updates as $q) {
            $set = substr($q, (int) stripos($q, ' SET '), (int) stripos($q, ' WHERE ') - (int) stripos($q, ' SET '));

            self::assertStringNotContainsString('user_id', $set, 'Ownership is stamped on create only.');
            self::assertStringContainsString('user_id', $q, 'The update must still be scoped to the owner.');
        }
    }

    public function test_console_run_stamps_the_empty_sentinel(): void
    {
        $this->conn->method('fetchOne')->willReturnOnConsecutiveCalls(false, 0);

        $captured = [];
        $this->conn->method('executeStatement')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured): int {
                if (str_contains($sql, 'INSERT INTO')) {
                    $captured = $params;
                }

                return 1;
            });

        $this->memory('')->set('c-cli', ['history' => []], 'conversations');

        self::assertSame('', $captured[2] ?? null);
    }

    public function test_get_denies_a_conversation_owned_by_another_user(): void
    {
        $this->conn->method('fetchAssociative')->willReturn([
            'id' => 'c1', 'namespace' => 'conversations', 'user_id' => 'bob@example.com',
            'title' => 't', 'metadata' => null, 'created_at' => '2026-01-01 00:00:00',
        ]);

        $this->expectException(ConversationAccessDeniedException::class);

        $this->memory('alice@example.com')->get('c1', 'conversations');
    }

    public function test_get_allows_the_owner(): void
    {
        $this->conn->method('fetchAssociative')->willReturn([
            'id' => 'c1', 'namespace' => 'conversations', 'user_id' => 'alice@example.com',
            'title' => 't', 'metadata' => null, 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->conn->method('fetchAllAssociative')->willReturn([]);

        $result = $this->memory('alice@example.com')->get('c1', 'conversations');

        self::assertIsArray($result);
        self::assertSame('c1', $result['id']);
    }

    public function test_get_allows_the_manage_all_tier_on_a_foreign_conversation(): void
    {
        $this->conn->method('fetchAssociative')->willReturn([
            'id' => 'c1', 'namespace' => 'conversations', 'user_id' => 'bob@example.com',
            'title' => 't', 'metadata' => null, 'created_at' => '2026-01-01 00:00:00',
        ]);
        $this->conn->method('fetchAllAssociative')->willReturn([]);

        $result = $this->memory('admin@example.com', true)->get('c1', 'conversations');

        self::assertSame('c1', $result['id'], 'the manage-all tier must reach a conversation owned by bob');
    }

    public function test_set_denies_writing_into_another_users_conversation(): void
    {
        $this->conn->method('fetchOne')->willReturn('bob@example.com');
        $this->conn->expects($this->never())->method('executeStatement');

        $this->expectException(ConversationAccessDeniedException::class);

        $this->memory('alice@example.com')->set('c1', ['history' => []], 'conversations');
    }

    public function test_forget_denies_another_users_conversation(): void
    {
        $this->conn->method('fetchOne')->willReturn('bob@example.com');
        $this->conn->expects($this->never())->method('executeStatement');

        $this->expectException(ConversationAccessDeniedException::class);

        $this->memory('alice@example.com')->forget('c1', 'conversations');
    }

    public function test_all_filters_by_owner_for_the_chat_tier(): void
    {
        $captured = [];
        $this->conn->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured): array {
                $captured = ['sql' => $sql, 'params' => $params];

                return [];
            });

        $this->memory('alice@example.com')->all('conversations');

        self::assertStringContainsString('user_id = ?', $captured['sql']);
        self::assertSame(['conversations', 'alice@example.com'], $captured['params']);
    }

    public function test_all_is_unfiltered_for_the_manage_all_tier(): void
    {
        $captured = [];
        $this->conn->method('fetchAllAssociative')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured): array {
                $captured = ['sql' => $sql, 'params' => $params];

                return [];
            });

        $this->memory('admin@example.com', true)->all('conversations');

        self::assertStringNotContainsString('user_id', $captured['sql']);
        self::assertSame(['conversations'], $captured['params']);
    }

    public function test_has_is_scoped_to_the_owner(): void
    {
        $captured = [];
        $this->conn->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured): int {
                $captured = ['sql' => $sql, 'params' => $params];

                return 0;
            });

        self::assertFalse($this->memory('alice@example.com')->has('c1', 'conversations'));
        self::assertStringContainsString('user_id = ?', $captured['sql']);
        self::assertSame(['c1', 'conversations', 'alice@example.com'], $captured['params']);
    }

    public function test_flush_only_removes_conversations_the_caller_can_see(): void
    {
        $captured = [];
        $this->conn->method('fetchFirstColumn')
            ->willReturnCallback(function (string $sql, array $params = []) use (&$captured): array {
                $captured = ['sql' => $sql, 'params' => $params];

                return ['c1'];
            });
        $this->conn->method('executeStatement')->willReturn(1);

        $this->memory('alice@example.com')->flush('conversations');

        self::assertStringContainsString('user_id = ?', $captured['sql']);
        self::assertSame(['conversations', 'alice@example.com'], $captured['params']);
    }

    public function test_a_denial_is_not_masked_as_a_memory_exception(): void
    {
        $this->conn->method('fetchOne')->willReturn('bob@example.com');

        try {
            $this->memory('alice@example.com')->forget('c1', 'conversations');
            self::fail('Expected the access denial to propagate.');
        } catch (ConversationAccessDeniedException $e) {
            self::assertSame('This conversation is unavailable.', $e->getMessage());
        }
    }
}
