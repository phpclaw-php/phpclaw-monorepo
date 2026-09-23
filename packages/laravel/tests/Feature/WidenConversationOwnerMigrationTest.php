<?php

declare(strict_types=1);

namespace PhpClaw\Laravel\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use PhpClaw\Laravel\PhpClawServiceProvider;

final class WidenConversationOwnerMigrationTest extends TestCase
{
    private const TABLE = 'phpclaw_conversations';

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
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('phpclaw_messages');
        Schema::dropIfExists(self::TABLE);

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('namespace', 100)->default('default');
            $table->unsignedBigInteger('user_id')->default(0);
            $table->string('title', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });
    }

    private function runUpgrade(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2024_01_01_000002_widen_phpclaw_conversation_owner.php';

        $migration->up();
    }

    private function seedRow(string $id, string|int $userId): void
    {
        DB::table(self::TABLE)->insert([
            'id' => $id,
            'namespace' => 'conversations',
            'user_id' => $userId,
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ]);
    }

    private function ownerOf(string $id): ?string
    {
        $value = DB::table(self::TABLE)->where('id', $id)->value('user_id');

        return $value === null ? null : (string) $value;
    }

    public function test_the_legacy_zero_sentinel_becomes_the_empty_sentinel(): void
    {
        $this->seedRow('legacy-cli', 0);

        $this->runUpgrade();

        self::assertSame('', $this->ownerOf('legacy-cli'));
    }

    public function test_a_real_numeric_owner_is_preserved(): void
    {
        $this->seedRow('legacy-user', 7);

        $this->runUpgrade();

        self::assertSame('7', $this->ownerOf('legacy-user'));
    }

    public function test_a_uuid_owner_is_storable_after_the_upgrade(): void
    {
        $this->runUpgrade();

        $this->seedRow('post-upgrade', '9f8c1e2a-4b6d-4f10-9c3e-7a51b2d8e4f7');

        self::assertSame('9f8c1e2a-4b6d-4f10-9c3e-7a51b2d8e4f7', $this->ownerOf('post-upgrade'));
    }

    public function test_the_upgrade_is_idempotent(): void
    {
        $this->seedRow('legacy-cli', 0);
        $this->seedRow('legacy-user', 7);

        $this->runUpgrade();
        $this->runUpgrade();

        self::assertSame('', $this->ownerOf('legacy-cli'));
        self::assertSame('7', $this->ownerOf('legacy-user'));
    }

    public function test_the_upgrade_is_a_no_op_when_the_table_is_absent(): void
    {
        Schema::dropIfExists(self::TABLE);

        $this->runUpgrade();

        self::assertFalse(Schema::hasTable(self::TABLE));
    }

    public function test_it_adds_the_owner_column_on_an_install_that_predates_ownership(): void
    {
        Schema::dropIfExists(self::TABLE);

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('namespace', 100)->default('default');
            $table->string('title', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
        });

        DB::table(self::TABLE)->insert([
            'id' => 'pre-ownership',
            'namespace' => 'conversations',
            'created_at' => '2024-01-01 00:00:00',
            'updated_at' => '2024-01-01 00:00:00',
        ]);

        self::assertFalse(Schema::hasColumn(self::TABLE, 'user_id'));

        $this->runUpgrade();

        self::assertTrue(Schema::hasColumn(self::TABLE, 'user_id'));
        self::assertSame('', $this->ownerOf('pre-ownership'));
    }
}
