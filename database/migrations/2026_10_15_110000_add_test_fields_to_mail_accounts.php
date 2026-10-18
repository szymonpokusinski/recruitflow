<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->timestamp('last_tested_at')->nullable()->after('status');
            $table->string('last_test_result', 30)->nullable()->after('last_tested_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_accounts', function (Blueprint $table) {
            $table->dropColumn(['last_tested_at', 'last_test_result']);
        });
    }
};
