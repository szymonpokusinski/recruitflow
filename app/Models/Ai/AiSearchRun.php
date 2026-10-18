<?php

declare(strict_types=1);

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiSearchRun extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['id', 'category_id', 'prompt_id', 'provider', 'model', 'status', 'scheduled_for', 'started_at', 'finished_at', 'error', 'meta'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'scheduled_for' => 'immutable_datetime', 'meta' => 'array'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AiCategory::class, 'category_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AiFinding::class, 'run_id');
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(AiCategoryPrompt::class, 'prompt_id');
    }
}
