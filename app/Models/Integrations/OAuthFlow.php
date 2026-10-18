<?php

namespace App\Models\Integrations;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OAuthFlow extends Model
{
    use HasUuids;

    protected $table = 'oauth_flows';

    protected $fillable = ['integration_account_id', 'secret_ref', 'status', 'redirect_uri', 'expires_at', 'error'];

    protected $hidden = ['secret_ref', 'redirect_uri'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class, 'integration_account_id');
    }
}
