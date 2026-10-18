<?php

namespace App\Models\Integrations;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    protected $table = 'integration_settings';

    protected $fillable = ['provider', 'client_id', 'secret_ref'];

    protected $hidden = ['secret_ref'];

    protected function casts(): array
    {
        return [];
    }
}
