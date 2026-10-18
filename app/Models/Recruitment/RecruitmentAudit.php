<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Model;

class RecruitmentAudit extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'recruitment_audits';

    protected $fillable = ['id', 'command_id', 'entity_type', 'entity_id', 'offer_key', 'action', 'actor', 'changes'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }
}
