<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Endpoint;
use App\Models\Workstation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ExpiryOverview
{
    public function rows(?int $companyId = null): Collection
    {
        $today = CarbonImmutable::today('Europe/Rome');
        $companies = Company::query()->when($companyId !== null, fn (Builder $query): Builder => $query->whereKey($companyId))->get()->keyBy('id');
        $rows = collect();

        foreach ($companies as $company) {
            $expiry = $company->technical_expires_on?->toDateString();
            $detail = $expiry === null
                ? 'Scadenza tecnica aziendale non disponibile in GravityZone. Non indica la copertura commerciale delle postazioni.'
                : 'Licenza tecnica aziendale GravityZone, distinta dalle coperture commerciali delle postazioni.';
            if (! $company->is_present) {
                $detail .= ' Azienda non restituita dall’ultima sincronizzazione: ultimo dato disponibile.';
            }
            if (filled($company->sync_error)) {
                $detail .= ' Lettura licenza non riuscita: '.$company->sync_error;
            }
            $key = 'company-'.$company->id;
            $rows->put($key, [
                'id' => $key, 'type' => 'technical', 'entity_id' => $company->id,
                'company_id' => $company->id, 'company' => $company->name, 'name' => $company->name,
                'expires_on' => $expiry, 'status' => $expiry === null ? 'missing' : $this->dateStatus($expiry, $today), 'detail' => $detail,
            ]);
        }

        $workstations = Workstation::query()->whereIn('company_id', $companies->keys())
            ->with(['coverages' => fn ($query) => $query->orderBy('starts_on')])
            ->withExists('coverageAssignments')->get();

        foreach ($workstations as $workstation) {
            $key = 'workstation-'.$workstation->id;
            $rows->put($key, [
                'id' => $key, 'type' => 'commercial', 'entity_id' => $workstation->id,
                'company_id' => $workstation->company_id, 'company' => $companies->get($workstation->company_id)->name,
                'name' => $workstation->name,
                ...$this->commercialPeriod($workstation, $today),
            ]);
        }

        $endpoints = Endpoint::query()->whereIn('company_id', $companies->keys())->with('workstation')->get();
        foreach ($endpoints as $endpoint) {
            $reasons = [];
            if ($endpoint->workstation === null) {
                $reasons[] = 'Endpoint da associare esplicitamente a una postazione locale.';
            } elseif ($endpoint->workstation->company_id !== $endpoint->company_id) {
                $reasons[] = 'L’endpoint e la postazione associata appartengono ad aziende diverse.';
            }
            if (! $endpoint->is_present) {
                $reasons[] = 'Endpoint non restituito dall’ultima sincronizzazione. Associazioni e storico sono conservati.';
            }
            if (! $companies->get($endpoint->company_id)->is_present) {
                $reasons[] = 'Azienda non restituita dall’ultima sincronizzazione: verifica i dati tecnici prima di procedere.';
            }
            if ($reasons === []) {
                continue;
            }
            $key = 'endpoint-'.$endpoint->id;
            $rows->put($key, [
                'id' => $key, 'type' => 'endpoint', 'entity_id' => $endpoint->id,
                'company_id' => $endpoint->company_id, 'company' => $companies->get($endpoint->company_id)->name,
                'name' => $endpoint->name, 'expires_on' => null, 'status' => 'verify', 'detail' => implode(' ', $reasons),
            ]);
        }

        return $rows;
    }

    private function commercialPeriod(Workstation $workstation, CarbonImmutable $today): array
    {
        $blocks = [];
        foreach ($workstation->coverages as $coverage) {
            $start = $coverage->starts_on->toDateString();
            $end = $coverage->ends_on->toDateString();
            $last = array_key_last($blocks);
            // Adjacent purchased periods cover continuously, while even a one-day gap remains visible.
            if ($last !== null && $start <= CarbonImmutable::parse($blocks[$last]['end'], 'Europe/Rome')->addDay()->toDateString()) {
                $blocks[$last]['end'] = max($blocks[$last]['end'], $end);
            } else {
                $blocks[] = ['start' => $start, 'end' => $end];
            }
        }

        $expiredEnd = null;
        $day = $today->toDateString();
        foreach ($blocks as $block) {
            if ($block['end'] < $day) {
                $expiredEnd = $block['end'];

                continue;
            }
            if ($block['start'] > $day) {
                return [
                    'expires_on' => $block['start'], 'status' => 'gap',
                    'detail' => 'Nessuna copertura commerciale attiva oggi. Prossimo inizio commerciale: '
                        .CarbonImmutable::parse($block['start'], 'Europe/Rome')->format('d/m/Y').'. La data mostrata è un inizio, non una scadenza.',
                ];
            }

            return [
                'expires_on' => $block['end'], 'status' => $this->dateStatus($block['end'], $today),
                'detail' => 'Copertura commerciale continuativa acquistata, compresi eventuali periodi successivi contigui. Ultimo giorno coperto incluso.',
            ];
        }

        if ($expiredEnd !== null) {
            return ['expires_on' => $expiredEnd, 'status' => 'expired', 'detail' => 'Copertura commerciale scaduta. Rinnovo da gestire; i periodi precedenti restano nello storico.'];
        }

        return [
            'expires_on' => null, 'status' => 'missing',
            'detail' => $workstation->coverage_assignments_exists
                ? 'Nessuna copertura commerciale attualmente assegnata. Le coperture trasferite restano nello storico della postazione.'
                : 'Nessuna copertura commerciale registrata per la postazione. La scadenza aziendale GravityZone non la sostituisce.',
        ];
    }

    private function dateStatus(string $date, CarbonImmutable $today): string
    {
        return $date < $today->toDateString() ? 'expired' : ($date <= $today->addDays(30)->toDateString() ? 'expiring' : 'active');
    }
}
