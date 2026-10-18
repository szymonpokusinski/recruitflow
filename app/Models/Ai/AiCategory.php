<?php

declare(strict_types=1);

namespace App\Models\Ai;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiCategory extends Model
{
    use HasFactory;

    protected $fillable = ['slug', 'name', 'is_active', 'schedule_cron', 'default_provider', 'settings'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'settings' => 'array'];
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(AiCategoryPrompt::class, 'category_id');
    }

    public function activePrompt(): HasOne
    {
        return $this->hasOne(AiCategoryPrompt::class, 'category_id')->where('is_active', true);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AiSearchRun::class, 'category_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(AiFinding::class, 'category_id');
    }
}
