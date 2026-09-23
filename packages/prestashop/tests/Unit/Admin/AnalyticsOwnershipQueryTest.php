<?php

declare(strict_types=1);

namespace PhpClaw\PrestaShop\Tests\Unit\Admin;

use PhpClaw\PrestaShop\Contracts\PsDbInterface;
use PHPUnit\Framework\TestCase;

final class OrphanAwareDb implements PsDbInterface
{
    public array $seen = [];

    public function query(string $sql, array $params = []): object
    {
        $this->seen[] = $sql;
        $r = new \stdClass;
        $r->rows = [];

        $isMessageCount = str_contains($sql, 'phpclaw_messages');
        $joinsConversations = str_contains($sql, 'INNER JOIN') && str_contains($sql, 'phpclaw_conversations');

        if ($isMessageCount && $joinsConversations) {
            $r->row = ['c' => 7];
        } elseif ($isMessageCount) {
            $r->row = ['c' => 9];
        } elseif (str_contains($sql, 'COUNT(DISTINCT')) {
            $r->row = ['c' => 2];
        } else {
            $r->row = ['c' => 3];
        }

        $r->rows = [$r->row];
        $r->num_rows = 1;

        return $r;
    }

    public function escape(string $value): string
    {
        return addslashes($value);
    }
}

final class AnalyticsOwnershipQueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Context::reset();
        \Tab::reset();
        \Profile::reset();
        \Employee::reset();
        require_once __DIR__.'/../../../upload/modules/phpclaw/controllers/admin/AdminPhpClawAnalyticsController.php';
    }

    protected function tearDown(): void
    {
        \Context::reset();
        \Tab::reset();
        \Profile::reset();
        \Employee::reset();
        parent::tearDown();
    }

    private function gather(PsDbInterface $db): array
    {
        $ref = new \ReflectionClass(\AdminPhpClawAnalyticsController::class);
        $controller = $ref->newInstanceWithoutConstructor();
        $m = $ref->getMethod('gatherStats');
        $m->setAccessible(true);

        return (array) $m->invoke($controller, $db, 'ps_');
    }

    private function actAsSuperAdmin(): void
    {
        $e = new \Employee;
        $e->id = 1;
        $e->id_profile = 1;
        $e->superAdmin = true;
        \Context::getContext()->employee = $e;
    }

    private function actAsChatTier(): void
    {
        \Tab::$idsByClass['AdminPhpClawDebug'] = 7;
        \Profile::grant(4, 7, 'view');
        $e = new \Employee;
        $e->id = 42;
        $e->id_profile = 4;
        $e->superAdmin = false;
        \Context::getContext()->employee = $e;
    }

    public function test_admin_message_count_excludes_messages_whose_conversation_is_gone(): void
    {
        $this->actAsSuperAdmin();
        $db = new OrphanAwareDb;

        $stats = $this->gather($db);

        self::assertSame(
            7,
            $stats['total_messages'],
            'The administrator total must come from the conversation-joined count, not the bare message table.',
        );
    }

    public function test_admin_conversation_count_carries_no_ownership_predicate(): void
    {
        $this->actAsSuperAdmin();
        $db = new OrphanAwareDb;

        $this->gather($db);

        $conversationCounts = array_values(array_filter(
            $db->seen,
            static fn (string $s): bool => str_contains($s, 'phpclaw_conversations')
                && str_contains($s, 'COUNT(*)'),
        ));

        self::assertNotSame([], $conversationCounts);

        foreach ($conversationCounts as $sql) {
            self::assertStringNotContainsString(
                'id_employee',
                $sql,
                'An administrator sees every conversation, including CLI rows owned by 0.',
            );
        }
    }

    public function test_chat_tier_message_count_is_scoped_through_the_conversation(): void
    {
        $this->actAsChatTier();
        $db = new OrphanAwareDb;

        $stats = $this->gather($db);

        self::assertSame(7, $stats['total_messages']);
    }

    public function test_chat_tier_scopes_every_count_by_a_single_equality(): void
    {
        $this->actAsChatTier();
        $db = new OrphanAwareDb;

        $this->gather($db);

        $scoped = array_values(array_filter(
            $db->seen,
            static fn (string $s): bool => str_contains($s, 'id_employee'),
        ));

        self::assertCount(3, $scoped, 'All three chat-tier counts must be ownership scoped.');

        foreach ($scoped as $sql) {
            self::assertSame(1, substr_count($sql, 'id_employee'));
        }
    }
}
