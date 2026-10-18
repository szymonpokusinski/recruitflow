<?php

namespace App\Models\Integrations;

use Illuminate\Database\Eloquent\Model;

class DataSourceRecord extends Model
{
    protected $table = 'data_source_records';

    protected $fillable = ['data_source_id', 'collection', 'external_key', 'data'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
