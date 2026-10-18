<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('name', 200);
            $table->boolean('is_active')->default(false)->index();
            $table->string('schedule_cron', 100)->nullable();
            $table->string('default_provider', 50)->nullable();
            $table->json('settings');
            $table->timestamps();
        });
        Schema::create('ai_category_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('ai_categories')->restrictOnDelete();
            $table->string('name', 200);
            $table->text('prompt_text');
            $table->unsignedInteger('version');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['category_id', 'version']);
        });
        Schema::create('ai_search_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('category_id')->constrained('ai_categories')->restrictOnDelete();
            $table->foreignId('prompt_id')->nullable()->constrained('ai_category_prompts')->restrictOnDelete();
            $table->string('provider', 50)->nullable();
            $table->string('model', 100)->nullable();
            $table->enum('status', ['queued', 'running', 'succeeded', 'failed', 'skipped']);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->json('meta');
            $table->timestamps();
            $table->index(['category_id', 'status']);
            $table->unique(['category_id', 'scheduled_for']);
        });
        Schema::create('ai_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('category_id')->constrained('ai_categories')->restrictOnDelete();
            $table->foreignUuid('run_id')->constrained('ai_search_runs')->restrictOnDelete();
            $table->string('provider', 50);
            $table->string('model', 100)->nullable();
            $table->string('title', 500);
            $table->text('url')->nullable();
            $table->decimal('price', 16, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('location', 500)->nullable();
            $table->text('description')->nullable();
            $table->json('raw');
            $table->decimal('confidence', 8, 6)->nullable();
            $table->string('external_id', 255)->nullable();
            $table->json('metadata');
            $table->char('hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->boolean('is_new')->default(true);
            $table->timestamps();
            $table->unique(['category_id', 'hash']);
            $table->index(['category_id', 'provider', 'external_id']);
            $table->index(['category_id', 'is_new', 'last_seen_at']);
        });
        Schema::create('ai_finding_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('ai_categories')->restrictOnDelete();
            $table->foreignUuid('finding_id')->constrained('ai_findings')->cascadeOnDelete();
            $table->string('type', 30);
            $table->char('identity_hash', 64);
            $table->unique(['category_id', 'identity_hash']);
        });

        // Local storage is SQLite: partial indexes enforce invariants across processes.
        DB::statement('CREATE UNIQUE INDEX ai_one_active_prompt ON ai_category_prompts(category_id) WHERE is_active = 1');
        DB::statement("CREATE UNIQUE INDEX ai_one_open_scan ON ai_search_runs(category_id) WHERE status IN ('queued', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_finding_identities');
        Schema::dropIfExists('ai_findings');
        Schema::dropIfExists('ai_search_runs');
        Schema::dropIfExists('ai_category_prompts');
        Schema::dropIfExists('ai_categories');
    }
};
