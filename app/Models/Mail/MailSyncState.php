<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Models\MailAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailSyncState extends Model
{
    use HasUuids;

    protected $table = 'mail_sync_states';

    protected $attributes = [
        'sync_status' => 'idle',
        'total_messages_count' => 0,
        'relevant_messages_count' => 0,
        'ignored_messages_count' => 0,
        'last_batch_count' => 0,
    ];

    protected $fillable = [
        'mail_account_id',
        'sync_status',
        'last_synced_at',
        'total_messages_count',
        'relevant_messages_count',
        'ignored_messages_count',
        'last_batch_count',
        'history_id',
        'imap_last_uid',
        'imap_uidvalidity',
        'last_error',
        'last_error_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'last_error_at' => 'datetime',
            'total_messages_count' => 'integer',
            'relevant_messages_count' => 'integer',
            'ignored_messages_count' => 'integer',
            'last_batch_count' => 'integer',
            'imap_last_uid' => 'integer',
            'imap_uidvalidity' => 'integer',
        ];
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }
}
