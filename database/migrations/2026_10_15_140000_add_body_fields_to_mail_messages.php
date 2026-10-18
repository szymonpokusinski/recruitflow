<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->text('snippet')->nullable()->after('subject');
            $table->mediumText('body_text')->nullable()->after('snippet');
            $table->mediumText('body_html')->nullable()->after('body_text');
            $table->text('to_email')->nullable()->after('reply_to');
            $table->text('cc_email')->nullable()->after('to_email');
            $table->boolean('has_attachments')->default(false)->after('body_html');
            $table->json('attachments')->nullable()->after('has_attachments');
            $table->json('raw_headers')->nullable()->after('attachments');
            $table->dateTime('body_fetched_at')->nullable()->after('raw_headers');
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropColumn([
                'snippet',
                'body_text',
                'body_html',
                'to_email',
                'cc_email',
                'has_attachments',
                'attachments',
                'raw_headers',
                'body_fetched_at',
            ]);
        });
    }
};
