<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Model;

class SyncOperation extends Model
{
    protected $table = 'sync_outbox';

    protected $fillable = ['operation_id', 'command_id', 'entity_type', 'entity_id', 'offer_key', 'operation_type', 'payload', 'base_values', 'base_version', 'source_id', 'status', 'attempts', 'next_retry_at', 'last_error', 'error_code', 'unsupported_fields', 'synced_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'base_values' => 'array', 'unsupported_fields' => 'array', 'attempts' => 'integer', 'next_retry_at' => 'datetime', 'synced_at' => 'datetime'];
    }
}
