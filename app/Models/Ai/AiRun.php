<?php

declare(strict_types=1);

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Model;

class AiRun extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'request_id', 'provider', 'model', 'requested_model', 'category', 'operation', 'status',
        'requested_at', 'finished_at', 'duration_ms', 'items_count', 'error_code', 'error',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
            'duration_ms' => 'integer', 'items_count' => 'integer',
        ];
    }
}
