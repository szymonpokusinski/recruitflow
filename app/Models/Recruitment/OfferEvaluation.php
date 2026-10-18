<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Model;

class OfferEvaluation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'offer_evaluations';

    protected $fillable = ['id', 'offer_id', 'profile_id', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
