<?php

namespace App\Models;

use App\Support\Euro;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Fillable(['contact_name', 'contact_email', 'contact_phone', 'notes'])]
class Company extends Model
{
    public function computers(): HasMany
    {
        return $this->hasMany(Computer::class);
    }

    public function renewals(): HasMany
    {
        return $this->hasMany(CompanyRenewal::class);
    }

    public function initializeDetectedSales(): int
    {
        return DB::transaction(function (): int {
            $company = self::query()->lockForUpdate()->findOrFail($this->id);
            $computers = $company->computers()->with(['periods', 'originPeriods', 'company', 'gravityzoneCompany'])->whereNotNull('first_seen_on')
                ->whereDoesntHave('periods')->orderBy('id')->lockForUpdate()->get();
            $count = 0;
            foreach ($computers->sortBy('first_seen_on') as $computer) {
                if ($computer->hasTransferredLicense() || ($computer->activated_on === null && ! $computer->canInitializeActivationFromFirstSeen($computer->first_seen_on->toDateString()))) {
                    continue;
                }
                $start = $computer->activated_on ?? $computer->first_seen_on;
                $expiry = $computer->expires_on;
                $month = $computer->first_seen_on->format('Y-m');
                $sale = $company->renewals()->where('detection_month', $month)->first() ?? $company->renewals()->make();
                if (! $sale->exists) {
                    $sale->forceFill(['detection_month' => $month, 'kind' => 'initial', 'occurred_on' => $computer->first_seen_on, 'starts_on' => $start,
                        'expires_on' => $expiry, 'quantity' => 0])->save();
                }
                $automaticActivation = $computer->activation_from_first_seen || $computer->activated_on === null;
                $computer->activation_from_first_seen = $automaticActivation;
                $computer->commercial_version++;
                $computer->save();
                $sale->periods()->forceCreate(['computer_id' => $computer->id, 'origin_computer_id' => $computer->id,
                    'starts_on' => $start, 'expires_on' => $expiry, 'product' => $computer->company_product, 'is_detected' => true]);
                $sale->quantity = max($sale->quantity, $sale->periods()->count());
                if (empty($sale->history) && $computer->first_seen_on->lt($sale->occurred_on)) {
                    $sale->occurred_on = $computer->first_seen_on;
                }
                $sale->refreshPeriodSummary();
                $count++;
            }

            return $count;
        });
    }

    public function moveInitialPeriods(int $sourceId, int $targetId, array $computerIds, array $expectedVersions, array $expectedComputerVersions): void
    {
        Gate::authorize('update', $this);
        DB::transaction(function () use ($sourceId, $targetId, $computerIds, $expectedVersions, $expectedComputerVersions): void {
            $company = self::query()->lockForUpdate()->findOrFail($this->id);
            $operations = $company->renewals()->whereKey([$sourceId, $targetId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $operations->get($sourceId);
            $target = $operations->get($targetId);
            if (! $source || ! $target || $sourceId === $targetId || $source->kind !== 'initial' || $target->kind !== 'initial' ||
                $source->is_regrouped || $target->occurred_on === null || $source->version !== ($expectedVersions[$sourceId] ?? null) || $target->version !== ($expectedVersions[$targetId] ?? null)) {
                throw ValidationException::withMessages(['target_id' => 'Scegli una vendita della stessa azienda. Se i dati sono cambiati, riapri lo spostamento.']);
            }
            $computerIds = array_values(array_unique(array_map('intval', $computerIds)));
            $computers = $company->computers()->whereKey($computerIds)->orderBy('id')->lockForUpdate()->get();
            $periods = $source->periods()->whereIn('computer_id', $computerIds)->get();
            if ($computerIds === [] || $periods->count() !== count($computerIds) || $computers->count() !== count($computerIds) ||
                $periods->contains(fn (ComputerPeriod $period): bool => $period->previous_period_id !== null) ||
                $target->periods()->whereIn('computer_id', $computerIds)->exists()) {
                throw ValidationException::withMessages(['computer_ids' => 'Scegli i computer della vendita di origine, senza periodi già presenti nella destinazione.']);
            }
            foreach ($computers as $computer) {
                if ($computer->commercial_version !== ($expectedComputerVersions[$computer->id] ?? null)) {
                    throw ValidationException::withMessages(['computer_ids' => 'Un computer è cambiato. Riapri lo spostamento.']);
                }
            }
            $entry = ['event' => 'regrouped', 'recorded_at' => now()->toIso8601String(), 'source_id' => $sourceId, 'target_id' => $targetId,
                'periods' => $periods->map(fn (ComputerPeriod $period): array => $period->only(['id', 'computer_id', 'starts_on', 'expires_on']))->all()];
            foreach ($periods as $period) {
                $period->forceFill(['company_renewal_id' => $targetId, 'is_detected' => false])->save();
            }
            $source->quantity = max($source->periods()->count(), $source->quantity - count($computerIds));
            $target->quantity = max($target->quantity, $target->periods()->count());
            foreach ([$source, $target] as $operation) {
                $operation->history = [...($operation->history ?? []), $entry];
                $operation->refreshPeriodSummary();
            }
            foreach ($computers as $computer) {
                $computer->commercial_version++;
                $computer->save();
            }
        });
    }

    public function saveLicensePeriod(array $data, ?int $recordId = null, ?int $expectedVersion = null): CompanyRenewal
    {
        Gate::authorize('update', $this);
        if ($recordId === null) {
            throw ValidationException::withMessages(['kind' => 'Registra una vendita o un rinnovo collegando almeno un computer.']);
        }

        return DB::transaction(function () use ($data, $recordId, $expectedVersion): CompanyRenewal {
            $company = self::query()->lockForUpdate()->findOrFail($this->id);
            $period = $company->renewals()->lockForUpdate()->findOrFail($recordId);

            if ($period->occurred_on !== null) {
                throw ValidationException::withMessages(['starts_on' => 'Modifica questa operazione dal registro commerciale.']);
            }

            if ($period->version !== $expectedVersion) {
                throw ValidationException::withMessages(['starts_on' => 'Il periodo è stato modificato nel frattempo. Riaprilo prima di salvare.']);
            }

            $rules = [
                'kind' => ['required', Rule::in(['initial', 'renewal'])],
                'starts_on' => ['required', 'date_format:Y-m-d'],
                'expires_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
                'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            ];
            if (array_diff(array_keys($data), array_keys($rules))) {
                throw ValidationException::withMessages(['kind' => 'Sono modificabili soltanto tipo, periodo e quantità.']);
            }
            $validated = Validator::make($data, $rules)->validate();
            $period->forceFill($validated);
            if ($period->isDirty()) {
                $period->version++;
            }
            $period->save();

            return $period;
        });
    }

    public function saveCommercialOperation(array $data, ?int $recordId = null, ?int $expectedVersion = null, array $expectedComputerVersions = []): CompanyRenewal
    {
        Gate::authorize('update', $this);

        return DB::transaction(function () use ($data, $recordId, $expectedVersion, $expectedComputerVersions): CompanyRenewal {
            $company = self::query()->lockForUpdate()->findOrFail($this->id);
            $operation = $recordId === null ? $company->renewals()->make() : $company->renewals()->lockForUpdate()->findOrFail($recordId);
            if ($operation->exists && $operation->version !== $expectedVersion) {
                throw ValidationException::withMessages(['occurred_on' => 'L’operazione è cambiata. Riaprila prima di salvare.']);
            }
            if ($operation->is_regrouped) {
                $data['periods'] = [];
            }
            $rules = [
                'kind' => ['required', Rule::in(array_keys(CompanyRenewal::KINDS))],
                'occurred_on' => ['required', 'date_format:Y-m-d'],
                'invoice_reference' => ['nullable', 'string', 'max:255'],
                'quote_reference' => ['nullable', 'string', 'max:255'],
                'amount' => ['nullable', 'required_if:kind,upgrade', 'string', 'regex:'.Euro::PATTERN],
                'quantity' => ['exclude_if:kind,upgrade', 'required', 'integer', $operation->is_regrouped ? 'min:0' : 'min:1', 'max:4294967295'],
                'periods' => ['exclude_if:kind,upgrade', $operation->is_regrouped ? 'present' : 'required', 'array', $operation->is_regrouped ? 'min:0' : 'min:1'],
                'periods.*' => ['array:computer_id,starts_on,expires_on'],
                'periods.*.computer_id' => ['required', 'integer', 'distinct'],
                'periods.*.starts_on' => ['required', 'date_format:Y-m-d'],
                'periods.*.expires_on' => [($operation->detection_month !== null || ($operation->exists && $operation->periods()->whereNull('expires_on')->exists())) ? 'nullable' : 'required', 'date_format:Y-m-d', 'after_or_equal:periods.*.starts_on'],
            ];
            if (array_diff(array_keys($data), ['kind', 'occurred_on', 'invoice_reference', 'quote_reference', 'amount', 'quantity', 'periods'])) {
                throw ValidationException::withMessages(['kind' => 'Sono modificabili soltanto i dati dell’operazione commerciale.']);
            }
            $data = Validator::make($data, $rules, [
                'amount.regex' => 'Inserisci un importo non negativo con al massimo due decimali.',
            ])->validate();
            if ($operation->exists && $operation->kind !== $data['kind']) {
                throw ValidationException::withMessages(['kind' => 'Il tipo di un’operazione registrata non può essere cambiato.']);
            }
            $computers = $company->computers()->with(['periods.operation', 'company', 'gravityzoneCompany'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = $operation->exists ? $operation->periods()->with('operation')->get()->keyBy('computer_id') : collect();
            $before = $operation->exists ? [
                'recorded_at' => now()->toIso8601String(),
                'previous' => $operation->only(['kind', 'occurred_on', 'starts_on', 'expires_on', 'quantity', 'billing_status', 'invoice_reference', 'quote_reference', 'product', 'price_cents', 'affected_periods']),
                'periods' => $existing->map(fn (ComputerPeriod $period): array => $period->only(['id', 'computer_id', 'starts_on', 'expires_on']))->values()->all(),
            ] : null;
            $company->load('renewals');
            $upgrades = $company->renewals->where('kind', 'upgrade');
            $periodRows = collect($data['periods'] ?? [])->map(fn (array $row): array => array_replace(['expires_on' => null], $row));
            if ($data['kind'] === 'upgrade') {
                if ($computers->keys()->sort()->values()->all() !== collect(array_keys($expectedComputerVersions))->map(fn ($id): int => (int) $id)->sort()->values()->all()) {
                    throw ValidationException::withMessages(['occurred_on' => 'L’elenco dei computer è cambiato. Riapri l’aggiornamento aziendale.']);
                }
                $affectedIds = $computers->keys();
            } else {
                $affectedIds = $periodRows->pluck('computer_id')->map(fn ($id): int => (int) $id);
                if ($data['quantity'] < $affectedIds->count()) {
                    throw ValidationException::withMessages(['quantity' => 'La quantità non può essere inferiore ai computer collegati.']);
                }
                if ($operation->exists && $existing->keys()->diff($affectedIds)->isNotEmpty()) {
                    throw ValidationException::withMessages(['periods' => 'I computer già collegati non possono essere rimossi. Usa la sostituzione per trasferire una licenza.']);
                }
            }
            foreach ($affectedIds as $id) {
                if (! $computers->has($id) || ! array_key_exists($id, $expectedComputerVersions) || $computers[$id]->commercial_version !== $expectedComputerVersions[$id]) {
                    throw ValidationException::withMessages(['periods' => 'Uno dei computer non appartiene all’azienda o è stato modificato. Riapri l’operazione.']);
                }
            }
            $attributes = collect($data)->except(['periods', 'amount'])->all();
            $attributes['price_cents'] = filled($data['amount'] ?? null) ? Euro::toCents($data['amount']) : null;
            $attributes['invoice_reference'] = $data['invoice_reference'] ?? null;
            $attributes['quote_reference'] = $data['quote_reference'] ?? null;
            if ($data['kind'] === 'upgrade') {
                $commercialChanged = ! $operation->exists || $operation->occurred_on->toDateString() !== $data['occurred_on'];
                if ($commercialChanged && $company->renewals()->where('kind', 'upgrade')->when($operation->exists, fn ($query) => $query->whereKeyNot($operation->id))
                    ->where('occurred_on', '>=', min($data['occurred_on'], $operation->occurred_on?->toDateString() ?? $data['occurred_on']))->exists()) {
                    throw ValidationException::withMessages(['occurred_on' => 'Esiste un aggiornamento aziendale successivo o nello stesso giorno. Correggi prima quello per mantenere coerente lo storico dei prodotti.']);
                }
                if ($commercialChanged) {
                    $snapshots = [];
                    foreach ($computers as $computer) {
                        if ($computer->periods->isEmpty() && $computer->activated_on !== null && $computer->expires_on !== null &&
                            $computer->activated_on->toDateString() <= $data['occurred_on'] && $computer->expires_on->toDateString() >= $data['occurred_on']) {
                            throw ValidationException::withMessages(['occurred_on' => 'Collega prima a una vendita o rinnovo i periodi attivi dei computer: '.$computer->display_name.'.']);
                        }
                        foreach ($computer->periods as $period) {
                            if ($period->starts_on->toDateString() <= $data['occurred_on'] && $period->expires_on === null) {
                                throw ValidationException::withMessages(['occurred_on' => 'Completa prima la scadenza di '.$computer->display_name.'.']);
                            }
                            if ($period->starts_on->toDateString() <= $data['occurred_on'] && $period->expires_on?->toDateString() >= $data['occurred_on']) {
                                $snapshots[] = [
                                    'period_id' => $period->id, 'computer_id' => $computer->id, 'computer_name' => $computer->display_name,
                                    'starts_on' => $period->starts_on->toDateString(), 'expires_on' => $period->expires_on->toDateString(),
                                    'previous_product' => $company->commercialProductOn($data['occurred_on'], $operation->id, $period),
                                    'product' => $operation->exists
                                        ? (collect($operation->affected_periods)->firstWhere('period_id', $period->id)['product'] ?? $operation->product)
                                        : $computer->company_product,
                                ];
                            }
                        }
                    }
                    if ($snapshots === []) {
                        throw ValidationException::withMessages(['occurred_on' => 'Non ci sono periodi collegati attivi alla decorrenza dell’aggiornamento.']);
                    }
                    $attributes['affected_periods'] = $snapshots;
                    $attributes['quantity'] = count($snapshots);
                    $attributes['starts_on'] = $data['occurred_on'];
                    $attributes['expires_on'] = max(array_column($snapshots, 'expires_on'));
                }
            } else {
                $attributes['starts_on'] = $periodRows->min('starts_on') ?? $operation->starts_on;
                $attributes['expires_on'] = $periodRows->contains('expires_on', null) ? null : $periodRows->max('expires_on');
                foreach ($periodRows as $index => $row) {
                    $computer = $computers[$row['computer_id']];
                    $old = $existing->get($computer->id);
                    if (! $old && $computer->periods->isEmpty() &&
                        ((! $computer->activation_from_first_seen && $computer->activated_on !== null && $computer->activated_on->toDateString() !== $row['starts_on']) ||
                        ($computer->expires_on !== null && $computer->expires_on->toDateString() !== $row['expires_on']))) {
                        throw ValidationException::withMessages(["periods.$index.starts_on" => 'Collega prima le date già presenti sul computer a una vendita o rinnovo. Potrai poi registrare il periodo successivo.']);
                    }
                    $others = $computer->periods->reject(fn (ComputerPeriod $period): bool => $period->id === $old?->id)->sortBy('starts_on');
                    if ($others->contains(fn (ComputerPeriod $period): bool => ($row['expires_on'] === null || $period->starts_on->toDateString() <= $row['expires_on']) && ($period->expires_on === null || $period->expires_on?->toDateString() >= $row['starts_on']))) {
                        throw ValidationException::withMessages(["periods.$index.starts_on" => 'Il periodo si sovrappone a un altro periodo della stessa licenza. La scadenza è l’ultimo giorno incluso.']);
                    }
                    if (! $old && $others->isNotEmpty() && ($data['kind'] === 'initial' || $others->last()->expires_on === null || $others->last()->expires_on->toDateString() >= $row['starts_on'])) {
                        throw ValidationException::withMessages(["periods.$index.starts_on" => 'Questo computer ha già uno storico: registra un rinnovo successivo all’ultimo periodo.']);
                    }
                    if ($old && ($others->contains(fn (ComputerPeriod $period): bool => ($period->id === $old->previous_period_id && $period->expires_on?->toDateString() >= $row['starts_on']) ||
                        ($period->previous_period_id === $old->id && $period->starts_on->toDateString() <= $row['expires_on'])))) {
                        throw ValidationException::withMessages(["periods.$index.starts_on" => 'La correzione deve mantenere l’ordine dei rinnovi già collegati.']);
                    }
                    if (! $old && $upgrades->contains(fn (CompanyRenewal $upgrade): bool => $upgrade->occurred_on->toDateString() >= $row['starts_on'] && ($row['expires_on'] === null || $upgrade->occurred_on->toDateString() <= $row['expires_on']))) {
                        throw ValidationException::withMessages(["periods.$index.starts_on" => 'Un aggiornamento aziendale è già registrato in questo periodo. Non è possibile aggiungere retroattivamente una licenza al suo conteggio.']);
                    }
                    if ($old && ($old->starts_on->toDateString() !== $row['starts_on'] || $old->expires_on?->toDateString() !== $row['expires_on'] || $operation->occurred_on?->toDateString() !== $data['occurred_on']) &&
                        $upgrades->contains(fn (CompanyRenewal $upgrade): bool => collect($upgrade->affected_periods)->contains('period_id', $old->id) || ($upgrade->occurred_on->toDateString() >= $row['starts_on'] && ($row['expires_on'] === null || $upgrade->occurred_on->toDateString() <= $row['expires_on'])))) {
                        throw ValidationException::withMessages(["periods.$index.starts_on" => 'Questo periodo è già interessato da un aggiornamento aziendale: le date e la data dell’operazione non possono essere cambiate.']);
                    }
                }
            }
            $operation->forceFill($attributes);
            $periodsChanged = $periodRows->contains(fn (array $row): bool => ! isset($existing[$row['computer_id']]) ||
                $existing[$row['computer_id']]->starts_on->toDateString() !== $row['starts_on'] || $existing[$row['computer_id']]->expires_on?->toDateString() !== $row['expires_on']);
            if ($operation->exists && ! $operation->isDirty() && ! $periodsChanged) {
                return $operation;
            }
            if ($operation->exists) {
                $operation->history = [...($operation->history ?? []), $before];
                $operation->version++;
            } else {
                $operation->version = 0;
            }
            $operation->save();
            foreach ($periodRows as $row) {
                $computer = $computers[$row['computer_id']];
                $period = $existing->get($computer->id);
                if (! $period) {
                    $previous = $computer->periods->sortByDesc('starts_on')->first();
                    if (! $previous) {
                        $computer->archiveUnlinkedCommercialData();
                    }
                    $period = new ComputerPeriod;
                    $period->forceFill(['company_renewal_id' => $operation->id, 'computer_id' => $computer->id, 'origin_computer_id' => $computer->id, 'previous_period_id' => $previous?->id, 'product' => $computer->company_product]);
                }
                $period->forceFill(['starts_on' => $row['starts_on'], 'expires_on' => $row['expires_on'], 'is_detected' => false])->save();
            }
            foreach ($affectedIds as $id) {
                $computers[$id]->commercial_version++;
                $computers[$id]->save();
            }

            return $operation->load('periods');
        });
    }

    public function commercialProductOn(string $date, ?int $exceptOperationId = null, ?ComputerPeriod $period = null): ?string
    {
        $upgrades = $this->renewals->where('kind', 'upgrade')
            ->filter(fn (CompanyRenewal $candidate): bool => $candidate->id !== $exceptOperationId && $candidate->occurred_on?->toDateString() <= $date)
            ->sortByDesc(fn (CompanyRenewal $candidate): string => $candidate->occurred_on->toDateString());
        $appliedUpgrade = $period === null ? null : $upgrades->first(fn (CompanyRenewal $candidate): bool => collect($candidate->affected_periods)->contains('period_id', $period->id));
        if ($appliedUpgrade !== null) {
            return collect($appliedUpgrade->affected_periods)->firstWhere('period_id', $period->id)['product'] ?? $appliedUpgrade->product;
        }

        return $period?->product;
    }

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
