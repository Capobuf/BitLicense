<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'api_url', 'api_key'])]
#[Hidden(['api_key'])]
class GravityZoneSetting extends Model
{
    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'endpoints_sync_attempted_at' => 'datetime',
            'endpoints_synced_at' => 'datetime',
            'api_key' => 'encrypted',
            'companies_sync_attempted_at' => 'datetime',
            'companies_synced_at' => 'datetime',
        ];
    }
}
