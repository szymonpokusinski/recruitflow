<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Model;

class RecruitmentSetting extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'key';

    protected $table = 'recruitment_settings';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
