<?php

namespace App\Models;

use App\Support\Euro;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Fillable(['company_id', 'name'])]
class Computer extends Model
{
    private const COMMERCIAL_FIELDS = ['product', 'activated_on', 'expires_on', 'price_cents', 'commercial_description', 'invoice_reference', 'quote_reference'];

    private const REMOTE_FIELDS = ['gravityzone_id', 'gravityzone_company_id', 'technical_name', 'platform', 'is_managed', 'is_isolated', 'is_present', 'last_seen_at', 'first_seen_on', 'gravityzone_license_status', 'license_checked_at', 'license_sync_error'];

    protected static function booted(): void
    {
        static::saving(function (Computer $computer): void {
            if ($computer->exists && $computer->isDirty('company_id')) {
                throw ValidationException::withMessages(['company_id' => 'L’azienda del computer non può essere modificata.']);
            }

            if ($computer->gravityzone_id === null) {
                Validator::make($computer->only(['name', 'company_id']), [
                    'name' => ['required', 'string', 'max:255'],
                    'company_id' => ['required', 'integer', 'exists:companies,id'],
                ])->validate();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_managed' => 'boolean', 'is_isolated' => 'boolean', 'is_present' => 'boolean',
            'last_seen_at' => 'datetime', 'activated_on' => 'date', 'expires_on' => 'date',
            'gravityzone_license_status' => 'integer', 'license_checked_at' => 'datetime',
            'first_seen_on' => 'date', 'activation_from_first_seen' => 'boolean',
            'price_cents' => 'integer', 'commercial_version' => 'integer', 'history' => 'array',
        ];
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->technical_name ?? $this->name);
    }

    protected function companyProduct(): Attribute
    {
        return Attribute::get(fn (): ?string => ($this->gravityzone_id === null ? $this->company : $this->gravityzoneCompany)?->technical_product);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function gravityzoneCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'gravityzone_company_id');
    }

    public function periods(): HasMany
    {
        return $this->hasMany(ComputerPeriod::class)->orderBy('starts_on');
    }

    public function originPeriods(): HasMany
    {
        return $this->hasMany(ComputerPeriod::class, 'origin_computer_id')->orderBy('starts_on');
    }

    public function hasTransferredLicense(): bool
    {
        return $this->originPeriods->isNotEmpty() || collect($this->history)->contains('event', 'transferred_out');
    }

    public function scopeUnlinked(Builder $query): Builder
    {
        return $query->whereDoesntHave('periods')->whereDoesntHave('originPeriods')
            ->where(function (Builder $history): void {
                if ($history->getConnection()->getDriverName() === 'sqlite') {
                    $history->whereRaw("NOT EXISTS (SELECT 1 FROM json_each(computers.history) WHERE json_extract(value, '$.event') = 'transferred_out')");
                } else {
                    $history->whereNull('history')->orWhereJsonDoesntContain('history', ['event' => 'transferred_out']);
                }
            });
    }

    public function scopeWithCommercialPeriods(Builder $query): Builder
    {
        return $query->with(['periods.operation', 'originPeriods.operation', 'company.renewals', 'gravityzoneCompany']);
    }

    public function effectivePeriod(): ?ComputerPeriod
    {
        if (! $this->exists || $this->getKey() === null) {
            return null;
        }
        $today = today('Europe/Rome')->toDateString();

        return $this->periods->filter(fn (ComputerPeriod $period): bool => $period->starts_on->toDateString() <= $today)
            ->sortByDesc('starts_on')->first() ?? $this->periods->sortBy('starts_on')->first();
    }

    public function nextPeriod(): ?ComputerPeriod
    {
        if ($this->getKey() === null) {
            return null;
        }

        return $this->periods->filter(fn (ComputerPeriod $period): bool => $period->starts_on->gt(today('Europe/Rome')))->sortBy('starts_on')->first();
    }

    public function renewalCount(): int
    {
        return $this->periods->filter(fn (ComputerPeriod $period): bool => $period->operation->kind === 'renewal')->count();
    }

    public function commercialProduct(): ?string
    {
        return $this->company->commercialProductOn(today('Europe/Rome')->toDateString(), period: $this->effectivePeriod()) ?? $this->product;
    }

    protected function activatedOn(): Attribute
    {
        return Attribute::get(fn ($value) => ($period = $this->effectivePeriod()) !== null ? $period->starts_on : ($value === null ? null : $this->asDateTime($value)->startOfDay()))->withoutObjectCaching();
    }

    protected function expiresOn(): Attribute
    {
        return Attribute::get(fn ($value) => ($period = $this->effectivePeriod()) !== null ? $period->expires_on : ($value === null ? null : $this->asDateTime($value)->startOfDay()))->withoutObjectCaching();
    }

    public static function commercialDateSql(string $field): string
    {
        if (! in_array($field, ['activated_on', 'expires_on'], true)) {
            throw new \InvalidArgumentException('Unsupported commercial date.');
        }
        $periodField = $field === 'activated_on' ? 'starts_on' : 'expires_on';
        $today = today('Europe/Rome')->toDateString();

        return "CASE WHEN EXISTS (SELECT 1 FROM computer_periods WHERE computer_id = computers.id) THEN (SELECT DATE(cp.$periodField) FROM computer_periods cp WHERE cp.computer_id = computers.id ORDER BY CASE WHEN DATE(cp.starts_on) <= '$today' THEN 0 ELSE 1 END, CASE WHEN DATE(cp.starts_on) <= '$today' THEN cp.starts_on END DESC, cp.starts_on ASC LIMIT 1) ELSE DATE(computers.$field) END";
    }

    public function archiveUnlinkedCommercialData(): void
    {
        $previous = $this->commercialSnapshot();
        if (array_filter($previous, fn ($value) => $value !== null) !== []) {
            $this->recordHistory('linked', $previous);
        }
        $this->forceFill(array_fill_keys(self::COMMERCIAL_FIELDS, null));
        $this->activation_from_first_seen = false;
    }

    public function commercialStatus(): string
    {
        $day = today('Europe/Rome');

        return match (true) {
            $this->activated_on === null || $this->expires_on === null => 'Da completare',
            $this->expires_on->lt($day) => 'Scaduta',
            $this->activated_on->gt($day) => 'Da attivare',
            $this->expires_on->lte($day->addDays(30)) => 'In scadenza',
            default => 'Attiva',
        };
    }

    public function gravityzoneLicenseDisplay(): array
    {
        if ($this->gravityzone_id === null) {
            return ['label' => 'Non collegato', 'color' => 'gray', 'description' => 'Computer manuale, senza stato GravityZone.'];
        }
        if ($this->is_managed !== true) {
            return ['label' => $this->is_managed === false ? 'Non gestito' : 'Non disponibile', 'color' => 'gray', 'description' => 'Stato licenza disponibile solo per endpoint gestiti.'];
        }

        $company = $this->gravityzoneCompany;
        $reason = match (true) {
            ! $this->is_present => 'Computer non più rilevato.',
            ! $company?->is_present => 'Azienda rilevata non più disponibile.',
            $company->gravityzone_type === 0 && $company->can_manage_security === false => 'Gestione della sicurezza non condivisa.',
            filled($this->license_sync_error) => 'Ultima lettura della licenza non riuscita.',
            filled($company->endpoints_sync_error) && ($this->license_checked_at === null || $company->endpoints_sync_attempted_at === null ||
                $this->license_checked_at->lt($company->endpoints_sync_attempted_at)) => 'Ultima sincronizzazione dei computer incompleta.',
            default => null,
        };
        [$label, $color] = match ($this->license_checked_at === null ? null : $this->gravityzone_license_status) {
            0 => ['Autenticazione in attesa', 'warning'],
            1 => ['Licenza attiva', 'success'],
            2 => ['Licenza Scaduta', 'danger'],
            6 => ['Assente / non applicabile', 'gray'],
            default => ['Non disponibile', 'gray'],
        };
        $description = $this->license_checked_at === null
            ? 'Nessuna lettura disponibile.'
            : 'Letto il '.$this->license_checked_at->copy()->timezone('Europe/Rome')->format('d/m/Y H:i').'.';
        if ($reason !== null) {
            if ($label !== 'Non disponibile') {
                $label = 'Ultimo stato: '.$label;
            }
            $color = 'gray';
            $description = 'Dato non aggiornato. '.$reason.' '.$description;
        }

        return compact('label', 'color', 'description');
    }

    public function updateCommercial(array $data, int $expectedVersion): void
    {
        DB::transaction(function () use ($data, $expectedVersion): void {
            $current = self::query()->lockForUpdate()->findOrFail($this->id);
            $current->ensureVersion($expectedVersion);
            if ($current->periods->isNotEmpty()) {
                if (array_diff(array_keys($data), ['name', 'notes'])) {
                    throw ValidationException::withMessages(['activated_on' => 'Il periodo è collegato a un’operazione: correggi date, importo e riferimenti dal registro aziendale.']);
                }
                $validated = Validator::make(array_replace(['name' => $current->name], $data), [
                    'name' => [$current->gravityzone_id === null ? 'required' : 'nullable', 'string', 'max:255'],
                    'notes' => ['nullable', 'string', 'max:10000'],
                ])->validate();
                if ($current->gravityzone_id !== null && array_key_exists('name', $data) && $data['name'] !== $current->name) {
                    throw ValidationException::withMessages(['name' => 'Il nome tecnico è gestito da GravityZone.']);
                }
                $current->forceFill($validated);
                if ($current->isDirty()) {
                    $current->commercial_version++;
                    $current->save();
                }

                return;
            }
            $rules = [
                'name' => [$current->gravityzone_id === null ? 'required' : 'nullable', 'string', 'max:255'],
                'product' => ['nullable', 'string', 'max:255'],
                'activated_on' => ['nullable', 'date_format:Y-m-d'],
                'expires_on' => ['nullable', 'date_format:Y-m-d'],
                'amount' => ['nullable', 'string', 'regex:'.Euro::PATTERN],
                'commercial_description' => ['nullable', 'string', 'max:10000'],
                'invoice_reference' => ['nullable', 'string', 'max:255'],
                'quote_reference' => ['nullable', 'string', 'max:255'],
                'notes' => ['nullable', 'string', 'max:10000'],
            ];
            if (array_diff(array_keys($data), array_keys($rules))) {
                throw ValidationException::withMessages(['activated_on' => 'Sono modificabili soltanto i dati commerciali del computer.']);
            }
            if ($current->gravityzone_id !== null && array_key_exists('name', $data) && $data['name'] !== $current->name) {
                throw ValidationException::withMessages(['name' => 'Il nome tecnico è gestito da GravityZone.']);
            }
            $data = array_map(fn ($value) => $value === '' ? null : $value, $data);
            $input = array_replace($current->commercialSnapshot(), ['name' => $current->name, 'notes' => $current->notes], $data);
            unset($input['price_cents']);
            $validated = Validator::make($input, $rules, ['amount.regex' => 'Inserisci un importo non negativo, senza separatori delle migliaia e con al massimo due decimali.'])->validate();
            if ($validated['activated_on'] !== null && $validated['expires_on'] !== null && $validated['expires_on'] < $validated['activated_on']) {
                throw ValidationException::withMessages(['expires_on' => 'La scadenza non può precedere l’attivazione.']);
            }
            if (array_key_exists('amount', $validated)) {
                $validated['price_cents'] = $validated['amount'] === null || $validated['amount'] === '' ? null : Euro::toCents($validated['amount']);
                unset($validated['amount']);
            }
            $previous = $current->commercialSnapshot();
            $current->forceFill($validated);
            if ($current->isDirty('activated_on')) {
                $current->activation_from_first_seen = false;
            }
            if ($current->isDirty()) {
                if ($current->isDirty(self::COMMERCIAL_FIELDS) && array_filter($previous, fn ($value) => $value !== null) !== []) {
                    $current->recordHistory('updated', $previous);
                }
                $current->commercial_version++;
                $current->save();
            }
        });
        $this->refresh();
    }

    public function transferTo(Computer $target, int $expectedVersion): void
    {
        DB::transaction(function () use ($target, $expectedVersion): void {
            Company::query()->whereKey($this->company_id)->lockForUpdate()->firstOrFail();
            $records = self::query()->whereKey([$this->id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $records->get($this->id);
            $destination = $records->get($target->id);
            if (! $source || ! $destination || $source->id === $destination->id || $source->company_id !== $destination->company_id) {
                throw ValidationException::withMessages(['target_id' => 'Scegli un altro computer della stessa azienda.']);
            }
            $source->ensureVersion($expectedVersion, 'target_id');
            if ($source->activated_on === null || $source->expires_on === null || $destination->hasCommercialData()) {
                throw ValidationException::withMessages(['target_id' => 'Servono un periodo completo sul computer originale e un sostituto privo di dati commerciali da sovrascrivere.']);
            }
            $period = $source->commercialSnapshot();
            $destination->discardEmptyDetectedSale();
            $source->recordHistory('transferred_out', $period, $destination->id, $destination->display_name);
            $destination->recordHistory('transferred_in', $destination->commercialSnapshot(), $source->id, $source->display_name);
            if ($source->periods->isNotEmpty()) {
                $source->periods()->update(['computer_id' => $destination->id]);
            }
            $destination->forceFill($period);
            $destination->activation_from_first_seen = false;
            $source->forceFill(array_fill_keys(self::COMMERCIAL_FIELDS, null));
            $source->activation_from_first_seen = false;
            $source->commercial_version++;
            $destination->commercial_version++;
            $source->save();
            $destination->save();
        });
        $this->refresh();
        $target->refresh();
    }

    public function reconcileWith(Computer $remote, int $expectedVersion): void
    {
        DB::transaction(function () use ($remote, $expectedVersion): void {
            Company::query()->whereKey($this->company_id)->lockForUpdate()->firstOrFail();
            $records = self::query()->whereKey([$this->id, $remote->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $local = $records->get($this->id);
            $imported = $records->get($remote->id);
            if (! $local || ! $imported || $local->id === $imported->id || $local->gravityzone_id !== null || $imported->gravityzone_id === null ||
                $local->company_id !== $imported->company_id || $imported->gravityzone_company_id !== $local->company_id ||
                ! $imported->is_present || ! $imported->gravityzoneCompany->is_present) {
                throw ValidationException::withMessages(['target_id' => 'Scegli un computer GravityZone ancora rilevato nella stessa azienda.']);
            }
            $local->ensureVersion($expectedVersion, 'target_id');
            if ($imported->hasCommercialData() || filled($imported->notes) || filled($imported->name) || ! empty($imported->history)) {
                throw ValidationException::withMessages(['target_id' => 'Il computer importato contiene già dati locali: non può essere unito senza perderli.']);
            }
            $local->recordHistory('reconciled', $local->commercialSnapshot(), $imported->id, $imported->display_name);
            if ($imported->activation_from_first_seen && $local->canInitializeActivationFromFirstSeen($imported->activated_on->toDateString())) {
                $local->activated_on = $imported->activated_on;
                $local->activation_from_first_seen = true;
            }
            $technical = $imported->only(self::REMOTE_FIELDS);
            $imported->discardEmptyDetectedSale();
            $imported->delete();
            $local->forceFill($technical);
            $local->commercial_version++;
            $local->save();
        });
        $this->refresh();
    }

    private function ensureVersion(int $expectedVersion, string $field = 'activated_on'): void
    {
        if ($this->commercial_version !== $expectedVersion) {
            throw ValidationException::withMessages([$field => 'I dati sono stati modificati nel frattempo. Riapri il computer prima di salvare.']);
        }
    }

    private function commercialSnapshot(): array
    {
        $data = $this->only(self::COMMERCIAL_FIELDS);
        $data['activated_on'] = $this->activated_on?->toDateString();
        $data['expires_on'] = $this->expires_on?->toDateString();

        return $data;
    }

    public function hasCommercialData(): bool
    {
        if (($this->periods->isNotEmpty() || $this->originPeriods->isNotEmpty()) && ! $this->hasOnlyDetectedSale()) {
            return true;
        }
        $data = $this->commercialSnapshot();
        if ($this->activation_from_first_seen) {
            unset($data['activated_on']);
        }

        return array_filter($data, fn ($value) => $value !== null && $value !== '') !== [];
    }

    public function hasOnlyDetectedSale(): bool
    {
        $period = $this->periods->count() === 1 ? $this->periods->first() : null;

        return $period !== null && $period->is_detected && $period->origin_computer_id === $this->id &&
            $period->expires_on === null && $period->starts_on->equalTo($this->first_seen_on) &&
            empty($period->operation->history) && $period->operation->price_cents === null &&
            blank($period->operation->product) && blank($period->operation->invoice_reference) && blank($period->operation->quote_reference) &&
            empty($this->history);
    }

    private function discardEmptyDetectedSale(): void
    {
        if (! $this->hasOnlyDetectedSale()) {
            return;
        }
        $period = $this->periods->first();
        $sale = $period->operation;
        $period->delete();
        $sale->quantity = max(0, $sale->quantity - 1);
        $sale->refreshPeriodSummary();
        $this->unsetRelation('periods')->unsetRelation('originPeriods');
    }

    public function canInitializeActivationFromFirstSeen(string $date): bool
    {
        return $this->periods->isEmpty() && $this->originPeriods->isEmpty() && $this->activated_on === null &&
            ($this->expires_on === null || $this->expires_on->toDateString() >= $date) &&
            ! collect($this->history)->contains(fn (array $entry): bool => ($entry['previous']['activated_on'] ?? null) !== null);
    }

    private function recordHistory(string $event, array $previous, ?int $relatedComputerId = null, ?string $relatedComputerName = null): void
    {
        $this->history = [...($this->history ?? []), [
            'event' => $event, 'recorded_at' => now()->toIso8601String(),
            'related_computer_id' => $relatedComputerId, 'related_computer_name' => $relatedComputerName, 'previous' => $previous,
        ]];
    }
}
