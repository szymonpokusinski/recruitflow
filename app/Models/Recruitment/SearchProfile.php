<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Model;

class SearchProfile extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'external_key', 'data', 'origin', 'local_overrides', 'remote_baseline', 'source_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'local_overrides' => 'array', 'remote_baseline' => 'array'];
    }
}
