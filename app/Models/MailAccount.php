<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MailAccount extends Model
{
    use HasUuids;

    protected $table = 'mail_accounts';

    protected $attributes = [
        'auth_type' => 'password',
        'status' => 'active',
        'auto_sync' => false,
    ];

    protected $fillable = [
        'email',
        'name',
        'auth_type',
        'provider',
        'credential_ref',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'oauth_provider',
        'token_expires_at',
        'status',
        'auto_sync',
        'last_tested_at',
        'last_test_result',
        'last_synced_at',
        'last_error',
    ];

    /**
     * The attributes that should be hidden for serialization.
     * Never leak credential_ref or any ciphertext/passwords in API responses.
     */
    protected $hidden = [
        'credential_ref',
    ];

    protected function casts(): array
    {
        return [
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'auto_sync' => 'boolean',
            'token_expires_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function isDueForSync(int $intervalMinutes = 5): bool
    {
        if (! in_array($this->status, ['active', 'connected'], true) || ! $this->auto_sync) {
            return false;
        }

        if ($intervalMinutes <= 0 || $this->last_synced_at === null) {
            return true;
        }

        return $this->last_synced_at->lte(now()->subMinutes($intervalMinutes));
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<MailAccount> $query
     * @return \Illuminate\Database\Eloquent\Builder<MailAccount>
     */
    public function scopeDueForAutoSync(\Illuminate\Database\Eloquent\Builder $query, int $intervalMinutes = 5): \Illuminate\Database\Eloquent\Builder
    {
        $base = $query->whereIn('status', ['active', 'connected'])->where('auto_sync', true);

        if ($intervalMinutes <= 0) {
            return $base;
        }

        $cutoff = now()->subMinutes($intervalMinutes);

        return $base->where(function (\Illuminate\Database\Eloquent\Builder $sub) use ($cutoff) {
            $sub->whereNull('last_synced_at')
                ->orWhere('last_synced_at', '<=', $cutoff);
        });
    }

    public function syncState(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\Mail\MailSyncState::class, 'mail_account_id');
    }

    public function messages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Mail\MailMessage::class, 'mail_account_id');
    }

    public function syncRules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Mail\MailSyncRule::class, 'mail_account_id');
    }
}
