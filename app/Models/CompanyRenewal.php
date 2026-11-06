<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyRenewal extends Model
{
    public const KINDS = ['initial' => 'Vendita', 'renewal' => 'Rinnovo', 'upgrade' => 'Aggiornamento aziendale'];

    protected static function booted(): void
    {
        static::saving(function (self $operation): void {
            // Legacy company periods may still change kind and then join the other sequence.
            if ($operation->number !== null && ! $operation->isDirty('kind')) {
                return;
            }

            // Commercial writes hold this company lock until their transaction commits.
            Company::whereKey($operation->company_id)->lockForUpdate()->firstOrFail();
            $lastNumber = self::where('company_id', $operation->company_id)->where('kind', $operation->kind)
                ->orderByDesc('number')->lockForUpdate()->value('number');
            $operation->number = ($lastNumber ?? 0) + 1;
        });
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'expires_on' => 'date',
            'occurred_on' => 'date',
            'quantity' => 'integer',
            'number' => 'integer',
            'version' => 'integer',
            'price_cents' => 'integer',
            'affected_periods' => 'array',
            'history' => 'array',
            'is_regrouped' => 'boolean',
        ];
    }

    protected function reference(): Attribute
    {
        return Attribute::get(fn (): string => ['initial' => 'V', 'renewal' => 'R', 'upgrade' => 'U'][$this->kind].$this->number);
    }

    /** Ordinal within the company and kind, e.g. "Seconda vendita", "Primo rinnovo". */
    protected function label(): Attribute
    {
        return Attribute::get(function (): string {
            $ordinals = ['Prim', 'Second', 'Terz', 'Quart', 'Quint', 'Sest', 'Settim', 'Ottav', 'Non', 'Decim'];
            $feminine = $this->kind === 'initial';
            $ordinal = isset($ordinals[$this->number - 1])
                ? $ordinals[$this->number - 1].($feminine ? 'a' : 'o')
                : $this->number.($feminine ? 'ª' : '°');

            return $ordinal.' '.['initial' => 'vendita', 'renewal' => 'rinnovo', 'upgrade' => 'aggiornamento aziendale'][$this->kind];
        });
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
