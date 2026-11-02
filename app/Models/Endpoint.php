<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([])]
class Endpoint extends Model
{
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function workstation(): HasOne
    {
        return $this->hasOne(Workstation::class);
    }

    protected function casts(): array
    {
        return [
            'is_managed' => 'boolean',
            'is_isolated' => 'boolean',
            'is_present' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
