<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable(['company_id', 'name', 'notes', 'company_product_id', 'commercial_description', 'installed_on'])]
class Workstation extends Model
{
    protected static function booted(): void
    {
        static::saving(function (Workstation $workstation): void {
            if ($workstation->company_product_id !== null && ! CompanyProduct::query()
                ->whereKey($workstation->company_product_id)->where('company_id', $workstation->company_id)->exists()) {
                throw ValidationException::withMessages(['company_product_id' => 'Scegli un prodotto della stessa azienda della postazione.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['installed_on' => 'date'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function companyProduct(): BelongsTo
    {
        return $this->belongsTo(CompanyProduct::class);
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(Endpoint::class);
    }

    public function coverages(): HasMany
    {
        return $this->hasMany(Coverage::class);
    }

    public function coverageAssignments(): HasMany
    {
        return $this->hasMany(CoverageAssignment::class);
    }

    public function linkEndpoint(Endpoint $endpoint): void
    {
        DB::transaction(function () use ($endpoint): void {
            $remote = Endpoint::query()->lockForUpdate()->findOrFail($endpoint->id);
            $local = self::query()->lockForUpdate()->findOrFail($this->id);

            if ($local->endpoint_id === $remote->id) {
                return;
            }

            if (! $remote->is_present || ! $remote->company->is_present || $local->company_id !== $remote->company_id) {
                throw ValidationException::withMessages(['workstation_id' => 'Scegli una postazione della stessa azienda e un endpoint ancora rilevato.']);
            }

            if ($local->endpoint_id !== null || self::query()->where('endpoint_id', $remote->id)->exists()) {
                throw ValidationException::withMessages(['workstation_id' => 'La postazione o l’endpoint sono già associati. Ricarica l’elenco e verifica il collegamento.']);
            }

            $local->forceFill(['endpoint_id' => $remote->id])->save();
        });
        $this->refresh();
    }
}
