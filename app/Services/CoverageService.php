<?php

namespace App\Services;

use App\Models\Coverage;
use App\Models\CoverageAssignment;
use App\Models\Workstation;
use App\Support\Euro;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CoverageService
{
    /** @return Collection<int, Coverage> */
    public function create(array $input): Collection
    {
        $selection = Validator::make($input, [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'workstation_ids' => ['required', 'array', 'min:1'],
            'workstation_ids.*' => ['required', 'integer', 'distinct', 'exists:workstations,id'],
        ])->validate();
        $data = $this->validatedData($input);

        return DB::transaction(function () use ($selection, $data): Collection {
            $workstations = Workstation::query()->whereIn('id', $selection['workstation_ids'])->orderBy('id')->lockForUpdate()->get();

            if ($workstations->count() !== count($selection['workstation_ids']) || $workstations->contains(fn (Workstation $workstation): bool => $workstation->company_id !== (int) $selection['company_id'])) {
                throw ValidationException::withMessages(['workstation_ids' => 'Seleziona soltanto postazioni della stessa azienda.']);
            }

            $created = new Collection;
            foreach ($workstations as $workstation) {
                $this->ensureNoOverlap($workstation->id, $data['starts_on'], $data['ends_on']);
                $coverage = new Coverage;
                $coverage->forceFill($data + ['company_id' => $workstation->company_id, 'workstation_id' => $workstation->id])->save();
                $this->recordAssignment($coverage, $workstation);
                $created->push($coverage);
            }

            return $created;
        });
    }

    public function update(Coverage $coverage, array $input): Coverage
    {
        $data = $this->validatedData($input);

        return DB::transaction(function () use ($coverage, $data): Coverage {
            Workstation::query()->lockForUpdate()->findOrFail($coverage->workstation_id);
            $current = Coverage::query()->lockForUpdate()->findOrFail($coverage->id);
            $this->ensureUnchangedAssignment($coverage, $current);
            $this->ensureNoOverlap($current->workstation_id, $data['starts_on'], $data['ends_on'], $current->id);
            $current->forceFill($data)->save();

            return $current;
        });
    }

    public function transfer(Coverage $coverage, Workstation $target): Coverage
    {
        return DB::transaction(function () use ($coverage, $target): Coverage {
            $workstations = Workstation::query()->whereIn('id', [$coverage->workstation_id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $current = Coverage::query()->lockForUpdate()->findOrFail($coverage->id);
            $destination = $workstations->get($target->id);

            if (! $destination || $destination->company_id !== $current->company_id) {
                throw ValidationException::withMessages(['target_workstation_id' => 'Scegli una postazione sostitutiva della stessa azienda.']);
            }

            if ($current->workstation_id === $destination->id) {
                return $current;
            }

            $this->ensureUnchangedAssignment($coverage, $current);
            $this->ensureNoOverlap($destination->id, $current->starts_on->toDateString(), $current->ends_on->toDateString(), $current->id);
            $current->assignments()->whereNull('released_at')->update(['released_at' => now()]);
            $current->forceFill(['workstation_id' => $destination->id])->save();
            $this->recordAssignment($current, $destination);

            return $current;
        });
    }

    private function validatedData(array $input): array
    {
        $data = Validator::make($input, [
            'starts_on' => ['required', 'date', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'amount' => ['required', 'string', 'regex:'.Euro::PATTERN],
            'invoice_reference' => ['nullable', 'string', 'max:255'],
            'quote_reference' => ['nullable', 'string', 'max:255'],
        ], ['amount.regex' => 'Inserisci un importo non negativo, senza separatori delle migliaia e con al massimo due decimali.'])->validate();
        $data['price_cents'] = Euro::toCents($data['amount']);
        unset($data['amount']);

        return $data;
    }

    private function ensureNoOverlap(int $workstationId, string $start, string $end, ?int $except = null): void
    {
        // Both boundary dates are covered; the next period must start on the following day.
        $overlap = Coverage::query()->where('workstation_id', $workstationId)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->whereDate('starts_on', '<=', $end)->whereDate('ends_on', '>=', $start)
            ->lockForUpdate()->first();

        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => "La postazione #{$workstationId} ha già una copertura sovrapposta. La scadenza è l’ultimo giorno coperto, incluso."]);
        }
    }

    private function ensureUnchangedAssignment(Coverage $expected, Coverage $current): void
    {
        if ($expected->workstation_id !== $current->workstation_id) {
            throw ValidationException::withMessages(['target_workstation_id' => 'La copertura è stata trasferita nel frattempo. Ricarica i dati prima di procedere.']);
        }
    }

    private function recordAssignment(Coverage $coverage, Workstation $workstation): void
    {
        $assignment = new CoverageAssignment;
        $assignment->forceFill(['coverage_id' => $coverage->id, 'workstation_id' => $workstation->id, 'assigned_at' => now()])->save();
    }
}
