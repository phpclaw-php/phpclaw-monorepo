<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the phpClaw conversations, messages, and memory tables.
 */
return new class extends Migration
{
    /**
     * Create the phpClaw tables.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('phpclaw_conversations', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('namespace', 100)->default('default');
            $table->string('user_id', 180)->default('');
            $table->string('title', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index(['namespace', 'created_at'], 'idx_namespace_created');
            $table->index(['user_id', 'namespace', 'updated_at'], 'idx_user_namespace_updated');
        });

        Schema::create('phpclaw_messages', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->char('conversation_id', 26);
            $table->enum('role', ['user', 'assistant', 'tool', 'system']);
            $table->longText('content')->nullable();
            $table->string('tool_name', 255)->nullable();
            $table->text('tool_input')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('conversation_id')
                ->references('id')
                ->on('phpclaw_conversations')
                ->onDelete('cascade');

            $table->index(['conversation_id', 'created_at'], 'idx_conv_created');
        });

        Schema::create('phpclaw_memory', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->string('namespace', 100)->default('default');
            $table->string('lookup_key', 255);
            $table->longText('value');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['namespace', 'lookup_key'], 'uq_namespace_key');
            $table->index(['namespace', 'expires_at'], 'idx_namespace_expires');
        });
    }

    /**
     * Drop the phpClaw tables.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('phpclaw_memory');
        Schema::dropIfExists('phpclaw_messages');
        Schema::dropIfExists('phpclaw_conversations');
    }
};
