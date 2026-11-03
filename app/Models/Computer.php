<?php

namespace App\Models;

use App\Support\Euro;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

#[Fillable(['company_id', 'name'])]
class Computer extends Model
{
    private const COMMERCIAL_FIELDS = ['product', 'activated_on', 'expires_on', 'price_cents', 'commercial_description', 'invoice_reference', 'quote_reference'];

    private const REMOTE_FIELDS = ['gravityzone_id', 'gravityzone_company_id', 'technical_name', 'platform', 'is_managed', 'is_isolated', 'is_present', 'last_seen_at'];

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

    public function updateCommercial(array $data, int $expectedVersion): void
    {
        DB::transaction(function () use ($data, $expectedVersion): void {
            $current = self::query()->lockForUpdate()->findOrFail($this->id);
            $current->ensureVersion($expectedVersion);
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
            $source->recordHistory('transferred_out', $period, $destination->id, $destination->display_name);
            $destination->recordHistory('transferred_in', $destination->commercialSnapshot(), $source->id, $source->display_name);
            $destination->forceFill($period);
            $source->forceFill(array_fill_keys(self::COMMERCIAL_FIELDS, null));
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
            $technical = $imported->only(self::REMOTE_FIELDS);
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
        return array_filter($this->commercialSnapshot(), fn ($value) => $value !== null && $value !== '') !== [];
    }

    private function recordHistory(string $event, array $previous, ?int $relatedComputerId = null, ?string $relatedComputerName = null): void
    {
        $this->history = [...($this->history ?? []), [
            'event' => $event, 'recorded_at' => now()->toIso8601String(),
            'related_computer_id' => $relatedComputerId, 'related_computer_name' => $relatedComputerName, 'previous' => $previous,
        ]];
    }
}
