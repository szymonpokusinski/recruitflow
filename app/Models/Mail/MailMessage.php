<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Models\MailAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailMessage extends Model
{
    use HasUuids;

    protected $table = 'mail_messages';

    protected $attributes = [
        'category' => 'recruitment',
        'status' => 'to_review',
        'is_manually_classified' => false,
    ];

    protected $fillable = [
        'mail_account_id',
        'provider_message_id',
        'thread_id',
        'message_id',
        'sender_email',
        'sender_name',
        'sender_domain',
        'reply_to',
        'to_email',
        'cc_email',
        'subject',
        'snippet',
        'body_text',
        'body_html',
        'has_attachments',
        'attachments',
        'raw_headers',
        'body_fetched_at',
        'sent_at',
        'category',
        'status',
        'matched_rule_id',
        'is_manually_classified',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'body_fetched_at' => 'datetime',
            'is_manually_classified' => 'boolean',
            'has_attachments' => 'boolean',
            'attachments' => 'array',
            'raw_headers' => 'array',
        ];
    }

    public function mailAccount(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function matchedRule(): BelongsTo
    {
        return $this->belongsTo(MailSyncRule::class, 'matched_rule_id');
    }

    public function scopeForAccount(Builder $query, string $accountId): Builder
    {
        return $query->where('mail_account_id', $accountId);
    }

    public function scopeToReview(Builder $query): Builder
    {
        return $query->where('status', 'to_review');
    }

    public function scopeRelevant(Builder $query): Builder
    {
        return $query->where('status', 'relevant');
    }

    public function scopeIgnored(Builder $query): Builder
    {
        return $query->where('status', 'ignored');
    }

    public function markAsRelevant(): bool
    {
        $this->status = 'relevant';
        $this->is_manually_classified = true;

        return $this->save();
    }

    public function markAsIgnored(): bool
    {
        $this->status = 'ignored';
        $this->is_manually_classified = true;

        return $this->save();
    }
}
