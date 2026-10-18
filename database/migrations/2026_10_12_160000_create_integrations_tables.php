<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->string('client_id');
            $table->uuid('secret_ref');
            $table->timestamps();
        });
        Schema::create('integration_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider');
            $table->string('name');
            $table->string('status')->default('pending');
            $table->uuid('credential_ref');
            $table->json('scopes')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('oauth_flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_account_id')->constrained()->cascadeOnDelete();
            $table->uuid('secret_ref');
            $table->string('status')->default('starting');
            $table->string('redirect_uri')->nullable();
            $table->timestamp('expires_at');
            $table->string('error')->nullable();
            $table->timestamps();
        });
        Schema::create('data_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('integration_account_id')->constrained()->restrictOnDelete();
            $table->string('type')->default('google_sheets');
            $table->string('name');
            $table->string('external_id');
            $table->boolean('enabled')->default(false);
            $table->boolean('read_only')->default(true);
            $table->unsignedInteger('sync_interval_minutes')->default(15);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
            $table->unique(['type', 'external_id']);
        });
        Schema::create('data_source_configurations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('data_source_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('sheet_id');
            $table->string('sheet_title');
            $table->string('role');
            $table->unsignedInteger('header_row')->default(1);
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['data_source_id', 'sheet_id']);
        });
        DB::statement("CREATE UNIQUE INDEX integration_primary_collection ON data_source_configurations(role) WHERE is_primary = 1 AND role IN ('offers','applications','events')");
        DB::statement("CREATE UNIQUE INDEX integration_source_role ON data_source_configurations(data_source_id, role) WHERE role <> 'other'");
        Schema::create('data_source_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('data_source_configuration_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->string('column_name');
            $table->timestamps();
            $table->unique(['data_source_configuration_id', 'field']);
            $table->unique(['data_source_configuration_id', 'column_name']);
        });
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('trigger');
            $table->unsignedInteger('records_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('error_code')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
        Schema::create('data_source_records', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('data_source_id')->constrained()->cascadeOnDelete();
            $table->string('collection');
            $table->string('external_key');
            $table->json('data');
            $table->timestamps();
            $table->unique(['data_source_id', 'collection', 'external_key']);
        });
        foreach (['offers', 'applications', 'recruitment_events'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('external_key')->unique();
                $table->json('data');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['recruitment_events', 'applications', 'offers', 'data_source_records', 'sync_runs', 'data_source_field_mappings', 'data_source_configurations', 'data_sources', 'oauth_flows', 'integration_accounts', 'integration_settings'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
