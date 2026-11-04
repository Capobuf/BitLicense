<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComputerPeriod extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'date', 'expires_on' => 'date', 'is_detected' => 'boolean'];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(CompanyRenewal::class, 'company_renewal_id');
    }

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function originComputer(): BelongsTo
    {
        return $this->belongsTo(Computer::class, 'origin_computer_id');
    }

    public function previousPeriod(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_period_id');
    }
}
