<?php

namespace App\Models\Integrations;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSourceConfiguration extends Model
{
    use HasUuids;

    protected $table = 'data_source_configurations';

    protected $fillable = ['data_source_id', 'sheet_id', 'sheet_title', 'role', 'header_row', 'is_primary', 'schema_profile'];

    protected $hidden = [];

    protected function casts(): array
    {
        return ['sheet_id' => 'integer', 'header_row' => 'integer', 'is_primary' => 'boolean'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DataSource::class, 'data_source_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(DataSourceFieldMapping::class);
    }
}
