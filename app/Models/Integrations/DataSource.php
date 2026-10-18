<?php

namespace App\Models\Integrations;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model
{
    use HasUuids;

    protected $table = 'data_sources';

    protected $fillable = ['integration_account_id', 'type', 'name', 'external_id', 'enabled', 'read_only', 'sync_interval_minutes', 'last_synced_at', 'last_tested_at', 'last_error', 'schema_metadata'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['schema_metadata' => 'array', 'enabled' => 'boolean', 'read_only' => 'boolean', 'sync_interval_minutes' => 'integer', 'last_synced_at' => 'datetime', 'last_tested_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(IntegrationAccount::class, 'integration_account_id');
    }

    public function configurations(): HasMany
    {
        return $this->hasMany(DataSourceConfiguration::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(DataSourceRecord::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
