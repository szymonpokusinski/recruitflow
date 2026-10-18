<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Model;

class SyncConflict extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'sync_conflicts';

    protected $fillable = ['id', 'operation_id', 'entity_type', 'entity_id', 'source_id', 'fields', 'status', 'resolved_at'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'resolved_at' => 'datetime'];
    }
}
