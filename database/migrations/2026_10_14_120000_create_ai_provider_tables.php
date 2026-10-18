<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_provider_settings', function (Blueprint $table) {
            $table->string('id', 50)->primary();
            $table->string('provider', 50);
            $table->string('model', 100)->nullable();
            $table->string('credential_ref', 100)->nullable();
            $table->timestamps();
        });
        Schema::create('ai_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->unique();
            $table->string('provider', 50)->index();
            $table->string('model', 100)->nullable();
            $table->string('requested_model', 100)->nullable();
            $table->string('category', 100)->index();
            $table->string('operation', 20);
            $table->string('status', 20)->index();
            $table->timestamp('requested_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedInteger('items_count')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['provider', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
        Schema::dropIfExists('ai_provider_settings');
    }
};
