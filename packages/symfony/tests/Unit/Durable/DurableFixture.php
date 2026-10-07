<?php

declare(strict_types=1);

namespace PhpClaw\Symfony\Tests\Unit\Durable;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PhpClaw\Claw;
use PhpClaw\Hooks\HookRegistry;
use PhpClaw\Symfony\Memory\DoctrineConversationMemory;
use PhpClaw\Symfony\Memory\DoctrineMemory;
use PhpClaw\Symfony\Memory\DoctrineRouterMemory;
use PhpClaw\Symfony\Migrations\Version20240101000001;
use PhpClaw\Symfony\RunApprovals;
use PhpClaw\Symfony\SymfonyIdentityResolver;
use Psr\Log\NullLogger;

trait DurableFixture
{
    private Connection $connection;

    private SymfonyIdentityResolver $identity;

    private SwitchableUser $user;

    private DoctrineConversationMemory $conversations;

    private ConflictingMemory $conflicting;

    private function bootDurableFixture(): void
    {
        HookRegistry::reset();
        CountingTool::$runs = [];
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $schema = new Schema;
        (new Version20240101000001($this->connection, new NullLogger))->up($schema);

        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $statement) {
            $this->connection->executeStatement($statement);
        }

        $this->user = new SwitchableUser;
        $tokens = new SwitchableTokenStorage($this->user);
        $checker = new SwitchableAuthorizationChecker($this->user);

        $this->identity = new SymfonyIdentityResolver($tokens, $checker);
        $this->conversations = new DoctrineConversationMemory($this->connection, true, $this->identity);
    }

    private function actAsUser(string $id, bool $manageAll = false): void
    {
        $this->user->id = $id;
        $this->user->manageAll = $manageAll;
    }

    private function durableClaw(array $plan, int $stepBudget = 0): Claw
    {
        $builder = Claw::builder()
            ->providerOverride(new ScriptedProvider($plan))
            ->memory($this->conflicting = new ConflictingMemory(new DoctrineRouterMemory($this->conversations, new DoctrineMemory($this->connection))))
            ->useDefaultGuards(false)
            ->tools([new CountingTool('refund'), new CountingTool('lookup')])
            ->durableRuns(stepBudget: $stepBudget);

        return $stepBudget > 0 ? $builder->build() : $builder->withSuspendableApproval()->build();
    }

    private function approvals(): RunApprovals
    {
        return new RunApprovals($this->identity, $this->conversations);
    }

    private function messageRoles(string $conversationId): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT role FROM phpclaw_messages WHERE conversation_id = ? ORDER BY created_at, id',
            [$conversationId],
        );
    }
}
