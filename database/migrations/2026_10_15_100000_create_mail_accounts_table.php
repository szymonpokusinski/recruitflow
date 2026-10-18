<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            $table->string('auth_type', 30)->default('password'); // 'password', 'oauth'
            $table->string('provider', 50)->nullable(); // 'custom', 'gmail', 'outlook', 'wp', 'onet'
            $table->uuid('credential_ref')->nullable();

            // Prepared for future IMAP configuration
            $table->string('imap_host')->nullable();
            $table->unsignedSmallInteger('imap_port')->nullable();
            $table->string('imap_encryption', 20)->nullable(); // 'ssl', 'tls', 'starttls', 'none'
            $table->string('imap_username')->nullable();

            // Prepared for future SMTP configuration
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption', 20)->nullable();
            $table->string('smtp_username')->nullable();

            // Prepared for future OAuth configuration
            $table->string('oauth_provider', 50)->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // Status and synchronization
            $table->string('status', 30)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_accounts');
    }
};
