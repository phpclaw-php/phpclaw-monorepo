<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings phpclaw_conversations.user_id to its owning shape: adds the column, widens it
 * so UUID and ULID keys fit, and moves the no-user sentinel from 0 to the empty string.
 */
return new class extends Migration
{
    private const TABLE = 'phpclaw_conversations';

    private const LEGACY_SENTINEL = '0';

    private const OWNER_INDEX = 'idx_user_namespace_updated';

    /**
     * Widen the owner column and rewrite the legacy sentinel.
     *
     * @return void
     */
    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (Schema::hasColumn(self::TABLE, 'user_id')) {
            $this->widenOwnerColumn();
        } else {
            $this->addOwnerColumn();
        }

        DB::table(self::TABLE)
            ->where('user_id', self::LEGACY_SENTINEL)
            ->update(['user_id' => '']);
    }

    /**
     * Restore the numeric owner column, mapping the empty sentinel back to 0.
     *
     * @return void
     */
    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        DB::table(self::TABLE)
            ->where('user_id', '')
            ->update(['user_id' => self::LEGACY_SENTINEL]);

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement('ALTER TABLE '.self::TABLE.' MODIFY user_id BIGINT UNSIGNED NOT NULL DEFAULT 0');

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN user_id DROP DEFAULT');
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN user_id TYPE BIGINT USING user_id::bigint');
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN user_id SET DEFAULT 0');
        }
    }

    /**
     * Add the owner column on an install created before ownership existed.
     *
     * @return void
     */
    private function addOwnerColumn(): void
    {
        Schema::table(self::TABLE, static function (Blueprint $table): void {
            $table->string('user_id', 180)->default('');
        });

        if (! $this->hasOwnerIndex()) {
            Schema::table(self::TABLE, static function (Blueprint $table): void {
                $table->index(['user_id', 'namespace', 'updated_at'], self::OWNER_INDEX);
            });
        }
    }

    /**
     * Whether the composite owner index is already present.
     *
     * @return bool
     */
    private function hasOwnerIndex(): bool
    {
        try {
            return Schema::getConnection()
                ->getSchemaBuilder()
                ->hasIndex(self::TABLE, self::OWNER_INDEX);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Convert the owner column to a 180-character string, per driver, skipping SQLite
     * whose type affinity already accepts string keys and cannot be retyped in place.
     *
     * @return void
     */
    private function widenOwnerColumn(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement(
                'ALTER TABLE '.self::TABLE." MODIFY user_id VARCHAR(180) NOT NULL DEFAULT ''"
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN user_id DROP DEFAULT');
            DB::statement(
                'ALTER TABLE '.self::TABLE.' ALTER COLUMN user_id TYPE VARCHAR(180) USING user_id::varchar(180)'
            );
            DB::statement('ALTER TABLE '.self::TABLE." ALTER COLUMN user_id SET DEFAULT ''");
        }
    }
};
