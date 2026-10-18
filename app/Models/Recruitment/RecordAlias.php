<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Model;

class RecordAlias extends Model
{
    protected $table = 'record_aliases';

    protected $fillable = ['collection', 'external_key', 'entity_id'];

    protected function casts(): array
    {
        return [];
    }
}
