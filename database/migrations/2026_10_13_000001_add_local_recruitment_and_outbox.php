<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['offers', 'applications', 'recruitment_events', 'search_profiles'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('origin')->default('google_sheets')->index();
                $table->json('local_overrides')->nullable();
                $table->json('remote_baseline')->nullable();
                $table->foreignUuid('source_id')->nullable()->references('id')->on('data_sources')->nullOnDelete();
            });
            DB::table($name)->update(['remote_baseline' => DB::raw('data')]);
        }
        // Imported sheets may legitimately contain multiple candidates per offer.
        // Only locally created personal applications are constrained to one per offer.
        DB::statement("CREATE UNIQUE INDEX local_application_offer ON applications(json_extract(data, '$.offerId')) WHERE origin = 'local'");
        Schema::table('data_sources', fn (Blueprint $table) => $table->json('schema_metadata')->nullable());
        Schema::create('recruitment_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
        Schema::create('local_commands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->string('payload_hash', 64);
            $table->json('result')->nullable();
            $table->timestamps();
        });
        Schema::create('sync_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->uuid('command_id')->index();
            $table->string('entity_type');
            $table->uuid('entity_id')->index();
            $table->string('offer_key')->nullable()->index();
            $table->string('operation_type')->default('upsert');
            $table->json('payload');
            $table->json('base_values');
            $table->string('base_version', 64);
            $table->foreignUuid('source_id')->nullable()->references('id')->on('data_sources')->nullOnDelete();
            $table->string('status')->default('local');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->string('last_error')->nullable();
            $table->string('error_code')->nullable();
            $table->json('unsupported_fields')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_retry_at', 'id']);
        });
        Schema::create('sync_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('operation_id')->nullable()->index();
            $table->string('entity_type');
            $table->uuid('entity_id')->index();
            $table->foreignUuid('source_id')->nullable()->references('id')->on('data_sources')->nullOnDelete();
            $table->json('fields');
            $table->string('status')->default('open')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('recruitment_audits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('command_id')->index();
            $table->string('entity_type');
            $table->uuid('entity_id')->index();
            $table->string('offer_key')->nullable();
            $table->string('action');
            $table->string('actor');
            $table->json('changes');
            $table->timestamps();
        });
        Schema::create('record_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('collection');
            $table->string('external_key');
            $table->uuid('entity_id');
            $table->timestamps();
            $table->unique(['collection', 'external_key']);
        });
        Schema::create('offer_evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('offer_id')->references('id')->on('offers')->restrictOnDelete();
            $table->foreignUuid('profile_id')->references('id')->on('search_profiles')->restrictOnDelete();
            $table->json('data');
            $table->timestamps();
            $table->unique(['offer_id', 'profile_id']);
        });
    }

    public function down(): void
    {
        // A rollback removes stage-4 local data; never run it on a user's active database.
        foreach (['offer_evaluations', 'record_aliases', 'recruitment_audits', 'sync_conflicts', 'sync_outbox', 'local_commands', 'recruitment_settings'] as $name) {
            Schema::dropIfExists($name);
        }
        DB::statement('DROP INDEX IF EXISTS local_application_offer');
        Schema::table('data_sources', fn (Blueprint $table) => $table->dropColumn('schema_metadata'));
        foreach (['offers', 'applications', 'recruitment_events', 'search_profiles'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                // SQLite cannot drop a column while its index still references it.
                $table->dropIndex(['origin']);
                $table->dropConstrainedForeignId('source_id');
                $table->dropColumn(['origin', 'local_overrides', 'remote_baseline']);
            });
        }
    }
};
