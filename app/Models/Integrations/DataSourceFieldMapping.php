<?php

namespace App\Models\Integrations;

use Illuminate\Database\Eloquent\Model;

class DataSourceFieldMapping extends Model
{
    protected $table = 'data_source_field_mappings';

    protected $fillable = ['data_source_configuration_id', 'field', 'column_name'];

    protected $hidden = [];

    protected function casts(): array
    {
        return [];
    }
}
