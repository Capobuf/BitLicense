<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['contact_name', 'contact_email', 'contact_phone', 'notes'])]
class Company extends Model
{
    protected function casts(): array
    {
        return [
            'endpoints_sync_attempted_at' => 'datetime',
            'endpoints_synced_at' => 'datetime',
            'technical_expires_on' => 'date',
            'license_synced_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_present' => 'boolean',
            'is_suspended' => 'boolean',
            'gravityzone_type' => 'integer',
            'can_manage_security' => 'boolean',
        ];
    }
}
