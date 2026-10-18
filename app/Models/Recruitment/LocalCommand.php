<?php

namespace App\Models\Recruitment;

use Illuminate\Database\Eloquent\Model;

class LocalCommand extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'local_commands';

    protected $fillable = ['id', 'type', 'payload_hash', 'result'];

    protected function casts(): array
    {
        return ['result' => 'array'];
    }
}
