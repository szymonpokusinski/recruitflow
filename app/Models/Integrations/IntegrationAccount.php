<?php

namespace App\Models\Integrations;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationAccount extends Model
{
    use HasUuids;

    protected $table = 'integration_accounts';

    protected $fillable = ['provider', 'name', 'status', 'credential_ref', 'scopes', 'token_expires_at'];

    protected $hidden = ['credential_ref'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'token_expires_at' => 'datetime'];
    }

    public function sources(): HasMany
    {
        return $this->hasMany(DataSource::class);
    }
}
