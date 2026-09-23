<?php

declare(strict_types=1);

namespace PhpClaw\Drupal\Controller\Admin;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use PhpClaw\Drupal\DrupalIdentityResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Analytics page: basic usage stats from the phpClaw database tables.
 */
final class PhpClawAnalyticsController extends ControllerBase
{
    /**
     * Create a new PhpClawAnalyticsController instance.
     *
     * @param  Connection  $database  The Drupal database connection.
     * @param  TimeInterface  $time  Drupal time service for the 24h activity window.
     * @param  LoggerInterface|null  $logger  phpClaw logger channel.
     * @param  int  $actingUserId  Drupal user ID the counts are scoped to; 0 means no logged-in user.
     * @param  bool  $manageAll  Whether the acting user may see every user's counts.
     * @return void
     */
    public function __construct(
        private readonly Connection $database,
        private readonly TimeInterface $time,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $actingUserId = 0,
        private readonly bool $manageAll = false,
    ) {}

    /**
     * Create a new controller instance from the service container.
     *
     * @param  ContainerInterface  $container  The Drupal service container.
     * @return static
     */
    public static function create(ContainerInterface $container): static
    {
        return new self(
            $container->get('database'),
            $container->get('datetime.time'),
            $container->get('logger.channel.phpclaw'),
            DrupalIdentityResolver::actingUserId(),
            DrupalIdentityResolver::manageAll(),
        );
    }

    /**
     * Render the analytics page with usage statistics.
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $stats = [
            'conversations' => 0,
            'messages' => 0,
            'active_24h' => 0,
        ];

        try {
            $schema = $this->database->schema();
            $hasConversations = $schema->tableExists('phpclaw_conversations');
            $hasMessages = $schema->tableExists('phpclaw_messages');

            if ($hasConversations) {
                $stats['conversations'] = $this->countConversations(null);
                $stats['active_24h'] = $this->countConversations(
                    $this->time->getRequestTime() - 86400,
                );
            }

            if ($hasMessages && ($hasConversations || $this->manageAll)) {
                $stats['messages'] = $this->countMessages();
            }
        } catch (\Throwable $e) {
            $this->logger?->error('@message', ['@message' => $e->getMessage()]);
        }

        return [
            '#theme' => 'phpclaw_admin_analytics',
            '#stats' => $stats,
            '#manage_all' => $this->manageAll,
            '#attached' => ['library' => ['phpclaw/admin.system']],
            '#cache' => ['max-age' => 0],
        ];
    }

    /**
     * Count conversations visible to the acting user.
     *
     * @param  int|null  $updatedSince  Unix timestamp lower bound on updated_at, or null for no bound.
     * @return int
     */
    private function countConversations(?int $updatedSince): int
    {
        $query = $this->database->select('phpclaw_conversations', 'c');

        if ($updatedSince !== null) {
            $query->condition('c.updated_at', $updatedSince, '>=');
        }

        if (! $this->manageAll) {
            $query->condition('c.user_id', $this->actingUserId);
        }

        return (int) $query->countQuery()->execute()->fetchField();
    }

    /**
     * Count every message for a manage-all caller, otherwise only those in the acting user's own conversations.
     *
     * @return int
     */
    private function countMessages(): int
    {
        $query = $this->database->select('phpclaw_messages', 'm');

        if (! $this->manageAll) {
            $query->join('phpclaw_conversations', 'c', 'c.id = m.conversation_id');
            $query->condition('c.user_id', $this->actingUserId);
        }

        return (int) $query->countQuery()->execute()->fetchField();
    }
}
