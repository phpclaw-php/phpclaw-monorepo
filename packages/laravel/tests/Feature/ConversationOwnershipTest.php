<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\Exceptions\ConversationAccessDeniedException;
use PhpClaw\Laravel\LaravelIdentityResolver;
use PhpClaw\Laravel\Memory\DatabaseConversationMemory;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class ConversationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private DatabaseConversationMemory $memory;

    protected function getPackageProviders($app): array
    {
        return [PhpClawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('phpclaw.api_key', 'test-key');
        $app['config']->set('phpclaw.store_messages', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate');
        $this->memory = new DatabaseConversationMemory;
    }

    private function loginAs(int|string $id): void
    {
        $this->actingAs(new GenericUser(['id' => $id]));
    }

    private function grantManageAll(): void
    {
        Gate::define(LaravelIdentityResolver::MANAGE_ALL_ABILITY, static fn (): bool => true);
    }

    private function ownerOf(string $id): ?string
    {
        $value = DB::table('phpclaw_conversations')->where('id', $id)->value('user_id');

        return $value === null ? null : (string) $value;
    }

    public function test_conversations_table_carries_the_ownership_column(): void
    {
        self::assertTrue(
            Schema::hasColumn('phpclaw_conversations', 'user_id'),
        );
    }

    public function test_create_stamps_the_authenticated_user(): void
    {
        $this->loginAs(7);

        $this->memory->set('conv-a', ['history' => []], 'conversations');

        self::assertSame('7', $this->ownerOf('conv-a'));
    }

    public function test_a_console_run_writes_the_empty_sentinel(): void
    {
        $this->memory->set('conv-cli', ['history' => []], 'conversations');

        self::assertSame('', $this->ownerOf('conv-cli'));
    }

    public function test_ownership_is_stamped_on_create_only_and_never_rewritten(): void
    {
        $this->loginAs(7);
        $this->memory->set('conv-b', ['history' => []], 'conversations');

        $this->grantManageAll();
        $this->loginAs(9);
        $this->memory->set('conv-b', ['history' => []], 'conversations');

        self::assertSame('7', $this->ownerOf('conv-b'), 'A later write must not change the owner.');
    }

    public function test_get_denies_a_conversation_owned_by_another_user(): void
    {
        $this->loginAs(7);
        $this->memory->set('conv-c', ['history' => []], 'conversations');

        $this->loginAs(9);

        $this->expectException(ConversationAccessDeniedException::class);
        $this->memory->get('conv-c', 'conversations');
    }

    public function test_get_allows_the_owner(): void
    {
        $this->loginAs(7);
        $this->memory->set('conv-d', ['history' => []], 'conversations');

        self::assertSame('conv-d', $this->memory->get('conv-d', 'conversations')['id']);
    }

    public function test_manage_all_reaches_a_foreign_conversation(): void
    {
        $this->loginAs(7);
        $this->memory->set('conv-e', ['history' => []], 'conversations');

        $this->loginAs(9);
        $this->grantManageAll();

        self::assertSame('conv-e', $this->memory->get('conv-e', 'conversations')['id']);
    }

    public function test_has_reports_false_instead_of_throwing_for_a_foreign_conversation(): void
    {
        $this->loginAs(7);
        $this->memory->set('conv-f', ['history' => []], 'conversations');

        $this->loginAs(9);

        self::assertFalse($this->memory->has('conv-f', 'conversations'));
    }

    public function test_forget_denies_a_foreign_conversation_and_leaves_the_row(): void
    {
        $this->loginAs(7);
        $this->memory->set('conv-g', ['history' => []], 'conversations');

        $this->loginAs(9);

        try {
            $this->memory->forget('conv-g', 'conversations');
            self::fail('forget() must refuse a conversation owned by another user.');
        } catch (ConversationAccessDeniedException) {

        }

        self::assertSame('7', $this->ownerOf('conv-g'), 'The victim row must survive.');
    }

    public function test_set_denies_writing_into_a_foreign_conversation(): void
    {
        $this->loginAs(7);
        $this->memory->set('conv-h', ['history' => []], 'conversations');

        $this->loginAs(9);

        $this->expectException(ConversationAccessDeniedException::class);
        $this->memory->set('conv-h', ['history' => []], 'conversations');
    }

    public function test_all_returns_only_the_callers_own_conversations(): void
    {
        $this->loginAs(7);
        $this->memory->set('mine-1', ['history' => []], 'conversations');
        $this->memory->set('mine-2', ['history' => []], 'conversations');

        $this->loginAs(9);
        $this->memory->set('theirs-1', ['history' => []], 'conversations');

        self::assertSame(['theirs-1'], array_keys($this->memory->all('conversations')));

        $this->loginAs(7);
        self::assertSame(['mine-1', 'mine-2'], array_keys($this->memory->all('conversations')));
    }

    public function test_manage_all_sees_every_conversation(): void
    {
        $this->loginAs(7);
        $this->memory->set('one', ['history' => []], 'conversations');
        $this->loginAs(9);
        $this->memory->set('two', ['history' => []], 'conversations');

        $this->grantManageAll();

        self::assertCount(2, $this->memory->all('conversations'));
    }

    public function test_flush_only_removes_the_callers_own_conversations(): void
    {
        $this->loginAs(7);
        $this->memory->set('keep-mine', ['history' => []], 'conversations');
        $this->loginAs(9);
        $this->memory->set('keep-theirs', ['history' => []], 'conversations');

        $this->loginAs(7);
        $this->memory->flush('conversations');

        self::assertNull($this->ownerOf('keep-mine'));
        self::assertSame('9', $this->ownerOf('keep-theirs'));
    }

    public function test_manage_all_ability_denies_by_default(): void
    {
        $this->loginAs(7);

        self::assertFalse(LaravelIdentityResolver::manageAll());
    }

    public function test_a_uuid_keyed_user_is_stamped_verbatim(): void
    {
        $this->loginAs('9f8c1e2a-4b6d-4f10-9c3e-7a51b2d8e4f7');

        $this->memory->set('conv-uuid', ['history' => []], 'conversations');

        self::assertSame('9f8c1e2a-4b6d-4f10-9c3e-7a51b2d8e4f7', $this->ownerOf('conv-uuid'));
    }

    public function test_a_uuid_keyed_user_cannot_read_another_uuid_users_conversation(): void
    {
        $this->loginAs('9f8c1e2a-4b6d-4f10-9c3e-7a51b2d8e4f7');
        $this->memory->set('conv-victim', ['history' => []], 'conversations');

        $this->loginAs('1d4b7c60-8e2f-4a93-b5d1-6c0e93f2a815');

        $this->expectException(ConversationAccessDeniedException::class);
        $this->memory->get('conv-victim', 'conversations');
    }

    public function test_a_ulid_keyed_user_never_collapses_to_the_sentinel(): void
    {
        $this->loginAs('01ARZ3NDEKTSV4RRFFQ69G5FAV');

        self::assertSame('01ARZ3NDEKTSV4RRFFQ69G5FAV', LaravelIdentityResolver::actingUserId());
    }

    public function test_all_scopes_correctly_for_uuid_keyed_users(): void
    {
        $this->loginAs('aaaaaaaa-0000-4000-8000-000000000001');
        $this->memory->set('uuid-mine', ['history' => []], 'conversations');

        $this->loginAs('bbbbbbbb-0000-4000-8000-000000000002');
        $this->memory->set('uuid-theirs', ['history' => []], 'conversations');

        self::assertSame(['uuid-theirs'], array_keys($this->memory->all('conversations')));
    }

    public function test_purge_user_removes_only_that_users_conversations_and_messages(): void
    {
        $this->loginAs(7);
        $this->memory->set('purge-mine', ['history' => []], 'conversations');

        $this->loginAs(9);
        $this->memory->set('purge-theirs', ['history' => []], 'conversations');

        $deleted = DatabaseConversationMemory::purgeUser('7');

        self::assertSame(1, $deleted);
        self::assertNull($this->ownerOf('purge-mine'));
        self::assertSame('9', $this->ownerOf('purge-theirs'));
    }

    public function test_purge_user_is_a_no_op_for_an_unknown_owner(): void
    {
        $this->loginAs(7);
        $this->memory->set('purge-keep', ['history' => []], 'conversations');

        self::assertSame(0, DatabaseConversationMemory::purgeUser('4242'));
        self::assertSame('7', $this->ownerOf('purge-keep'));
    }

    public function test_purge_user_works_for_a_uuid_keyed_owner(): void
    {
        $this->loginAs('cccccccc-0000-4000-8000-000000000003');
        $this->memory->set('purge-uuid', ['history' => []], 'conversations');

        self::assertSame(1, DatabaseConversationMemory::purgeUser('cccccccc-0000-4000-8000-000000000003'));
        self::assertNull($this->ownerOf('purge-uuid'));
    }

    public function test_a_configured_admin_id_grants_manage_all(): void
    {
        config(['phpclaw.admin_ids' => ['7']]);
        $this->loginAs(7);

        self::assertTrue(LaravelIdentityResolver::manageAll());
    }

    public function test_a_user_not_in_the_admin_list_is_not_granted_manage_all(): void
    {
        config(['phpclaw.admin_ids' => ['7']]);
        $this->loginAs(9);

        self::assertFalse(LaravelIdentityResolver::manageAll());
    }

    public function test_an_empty_admin_list_grants_nobody(): void
    {
        config(['phpclaw.admin_ids' => []]);
        $this->loginAs(7);

        self::assertFalse(LaravelIdentityResolver::manageAll());
    }

    public function test_a_guest_never_matches_a_blank_admin_entry(): void
    {
        config(['phpclaw.admin_ids' => ['', ' ']]);

        self::assertFalse(LaravelIdentityResolver::manageAll());
    }

    public function test_a_uuid_admin_id_grants_manage_all(): void
    {
        config(['phpclaw.admin_ids' => ['dddddddd-0000-4000-8000-000000000004']]);
        $this->loginAs('dddddddd-0000-4000-8000-000000000004');

        self::assertTrue(LaravelIdentityResolver::manageAll());
    }

    public function test_an_admin_id_reaches_a_foreign_conversation(): void
    {
        $this->loginAs(7);
        $this->memory->set('admin-target', ['history' => []], 'conversations');

        config(['phpclaw.admin_ids' => ['9']]);
        $this->loginAs(9);

        self::assertSame('admin-target', $this->memory->get('admin-target', 'conversations')['id']);
    }
}
