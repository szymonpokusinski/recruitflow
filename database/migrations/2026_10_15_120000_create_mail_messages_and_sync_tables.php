<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_sync_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('system_key', 100)->nullable()->unique();
            $table->string('name', 255);
            $table->boolean('is_system')->default(false)->index();
            $table->foreignUuid('mail_account_id')->nullable()->constrained('mail_accounts')->nullOnDelete();
            $table->string('portal_name', 100)->nullable();
            $table->string('sender_email', 255)->nullable();
            $table->string('sender_domain', 255)->nullable();
            $table->json('subject_keywords')->nullable();
            $table->string('category', 50)->default('recruitment'); // 'job_offer', 'recruitment', 'other'
            $table->string('action', 30)->default('qualify'); // 'qualify', 'ignore'
            $table->integer('priority')->default(100);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mail_account_id')->constrained('mail_accounts')->cascadeOnDelete();
            $table->string('provider_message_id', 255);
            $table->string('thread_id', 255)->nullable();
            $table->string('message_id', 255)->nullable()->index();
            $table->string('sender_email', 255);
            $table->string('sender_name', 255)->nullable();
            $table->string('sender_domain', 255)->index();
            $table->string('reply_to', 255)->nullable();
            $table->text('subject');
            $table->timestamp('sent_at')->index();
            $table->string('category', 50)->default('recruitment'); // 'job_offer', 'recruitment', 'other'
            $table->string('status', 30)->default('to_review'); // 'new', 'to_review', 'relevant', 'ignored'
            $table->foreignUuid('matched_rule_id')->nullable()->constrained('mail_sync_rules')->nullOnDelete();
            $table->boolean('is_manually_classified')->default(false);
            $table->timestamps();

            $table->unique(['mail_account_id', 'provider_message_id'], 'mail_messages_account_provider_uid_unique');
            $table->index(['mail_account_id', 'status']);
            $table->index(['mail_account_id', 'category']);
        });

        Schema::create('mail_sync_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('mail_account_id')->unique()->constrained('mail_accounts')->cascadeOnDelete();
            $table->string('sync_status', 30)->default('idle'); // 'idle', 'syncing', 'success', 'failed'
            $table->timestamp('last_synced_at')->nullable();
            $table->unsignedInteger('total_messages_count')->default(0);
            $table->unsignedInteger('relevant_messages_count')->default(0);
            $table->unsignedInteger('ignored_messages_count')->default(0);
            $table->unsignedInteger('last_batch_count')->default(0);
            $table->string('history_id', 255)->nullable(); // Gmail historyId cursor
            $table->unsignedBigInteger('imap_last_uid')->nullable(); // IMAP last UID
            $table->unsignedBigInteger('imap_uidvalidity')->nullable(); // IMAP UIDVALIDITY
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sync_states');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_sync_rules');
    }
};
