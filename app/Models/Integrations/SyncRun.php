<?php

namespace App\Models\Integrations;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    use HasUuids;

    protected $table = 'sync_runs';

    protected $fillable = ['data_source_id', 'status', 'trigger', 'records_count', 'started_at', 'finished_at', 'error_code', 'error'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['records_count' => 'integer', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
