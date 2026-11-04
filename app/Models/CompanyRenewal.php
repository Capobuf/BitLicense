<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyRenewal extends Model
{
    public const KINDS = ['initial' => 'Vendita', 'renewal' => 'Rinnovo', 'upgrade' => 'Aggiornamento aziendale'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'expires_on' => 'date',
            'occurred_on' => 'date',
            'quantity' => 'integer',
            'version' => 'integer',
            'price_cents' => 'integer',
            'affected_periods' => 'array',
            'history' => 'array',
            'is_regrouped' => 'boolean',
        ];
    }

    protected function reference(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->kind) {
            'initial' => 'V', 'renewal' => 'R', 'upgrade' => 'U', default => '',
        }.$this->id);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(ComputerPeriod::class);
    }

    public function refreshPeriodSummary(): void
    {
        $periods = $this->periods()->get();
        $this->starts_on = $periods->min('starts_on') ?? $this->starts_on;
        $this->expires_on = $periods->contains('expires_on', null) ? null : $periods->max('expires_on');
        $this->is_regrouped = $periods->isEmpty();
        $this->version++;
        $this->save();
    }
}
