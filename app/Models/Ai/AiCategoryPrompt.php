<?php

declare(strict_types=1);

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiCategoryPrompt extends Model
{
    use HasFactory;

    protected $fillable = ['category_id', 'name', 'prompt_text', 'version', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'version' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AiCategory::class, 'category_id');
    }
}
