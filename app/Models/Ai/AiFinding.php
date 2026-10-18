<?php

declare(strict_types=1);

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiFinding extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id', 'category_id', 'run_id', 'provider', 'model', 'title', 'url', 'price',
        'currency', 'location', 'description', 'raw', 'confidence', 'external_id',
        'metadata', 'hash', 'first_seen_at', 'last_seen_at', 'is_new',
    ];

    protected function casts(): array
    {
        return [
            'raw' => 'array', 'metadata' => 'array', 'is_new' => 'boolean',
            'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime',
            'price' => 'float', 'confidence' => 'float',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AiCategory::class, 'category_id');
    }

    /** The last run that observed this finding, not a second copy of an offer. */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiSearchRun::class, 'run_id');
    }
}
