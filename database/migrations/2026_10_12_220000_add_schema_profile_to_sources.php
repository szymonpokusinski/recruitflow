<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_source_configurations', fn (Blueprint $table) => $table->string('schema_profile')->default('generic'));
    }

    public function down(): void
    {
        Schema::table('data_source_configurations', fn (Blueprint $table) => $table->dropColumn('schema_profile'));
    }
};
