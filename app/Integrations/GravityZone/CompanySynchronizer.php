<?php

namespace App\Integrations\GravityZone;

use App\Models\Company;
use App\Models\GravityZoneSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

class CompanySynchronizer
{
    public function sync(): int
    {
        $lock = Cache::lock('gravityzone-sync', 3600);

        $acquired = false;

        try {
            $acquired = $lock->get();

            if (! $acquired) {
                throw new GravityZoneException('Una sincronizzazione GravityZone è già in corso. Attendi prima di riprovare.');
            }

            $settings = GravityZoneSetting::find(1);

            if (! $settings) {
                throw new GravityZoneException('Configura prima l’integrazione GravityZone come amministratore.');
            }

            $settings->forceFill([
                'companies_sync_attempted_at' => now(),
                'companies_sync_error' => GravityZoneSetting::SYNC_IN_PROGRESS,
            ])->save();

            $client = app(GravityZoneClient::class);
            $companies = $client->getCompaniesList($settings);
            $ids = array_column($companies, 'id');
            $seenAt = now();

            DB::transaction(function () use ($companies, $seenAt, $settings): void {
                $rows = array_map(fn (array $company): array => [
                    'gravityzone_id' => $company['id'],
                    'name' => $company['name'],
                    'last_seen_at' => $seenAt,
                    'is_present' => true,
                    'sync_error' => 'I dati tecnici aziendali non sono stati aggiornati nell’ultimo tentativo.',
                ], $companies);

                Company::upsert($rows, ['gravityzone_id'], ['name', 'last_seen_at', 'is_present', 'sync_error', 'updated_at']);
                $settings->forceFill(['companies_synced_at' => $seenAt])->save();
            });

            $failures = 0;
            $interruptedBy = null;
            $firstError = null;

            $pending = Company::query()->whereIn('gravityzone_id', $ids)->get();

            for ($index = 0; $index < $pending->count(); $index++) {
                $company = $pending[$index];
                try {
                    if ($interruptedBy !== null) {
                        throw new GravityZoneException($interruptedBy);
                    }

                    // Licensing allows five requests/second; stay below that limit without retries.
                    Sleep::for(250)->milliseconds();
                    $license = $client->getLicenseInfo($settings, $company->gravityzone_id);
                    $company->forceFill($license + ['license_synced_at' => now()])->save();

                    Sleep::for(250)->milliseconds();
                    $details = $client->getCompanyDetails($settings, $company->gravityzone_id);
                    $company->forceFill($details + ['sync_error' => null])->save();

                    if ($company->gravityzone_type === null || ($company->gravityzone_type === 0 && $company->can_manage_security === null)) {
                        throw new GravityZoneException('Impossibile verificare i clienti: tipo aziendale o condivisione della sicurezza non disponibili dall’API.');
                    }

                    if ($company->gravityzone_type === 0 && $company->can_manage_security === true) {
                        Sleep::for(250)->milliseconds();
                        $children = $client->getCompaniesList($settings, $company->gravityzone_id);
                        $rows = [];

                        foreach ($children as $child) {
                            if (in_array($child['id'], $ids, true)) {
                                continue;
                            }

                            $ids[] = $child['id'];
                            $rows[] = [
                                'gravityzone_id' => $child['id'], 'name' => $child['name'],
                                'last_seen_at' => $seenAt, 'is_present' => true,
                                'sync_error' => 'I dati tecnici aziendali non sono stati aggiornati nell’ultimo tentativo.',
                            ];
                        }

                        if ($rows !== []) {
                            Company::upsert($rows, ['gravityzone_id'], ['name', 'last_seen_at', 'is_present', 'sync_error', 'updated_at']);
                            $pending = $pending->concat(Company::query()->whereIn('gravityzone_id', array_column($rows, 'gravityzone_id'))->get());
                        }
                    }
                } catch (GravityZoneException $exception) {
                    $failures++;
                    $firstError ??= $exception->getMessage();
                    $company->forceFill(['sync_error' => $exception->getMessage()])->save();

                    if ($exception->stopSynchronization) {
                        $interruptedBy = $exception->getMessage();
                    }
                }
            }

            if ($failures > 0) {
                throw new GravityZoneException("Elenco aziende aggiornato. Aziende con dati tecnici non aggiornati: {$failures}. {$firstError}");
            }

            Company::query()->whereNotIn('gravityzone_id', $ids)->update(['is_present' => false]);
            $settings->forceFill(['companies_sync_error' => null])->save();

            return count($ids);
        } catch (GravityZoneException|QueryException $exception) {
            if ($exception instanceof QueryException) {
                $exception = new GravityZoneException('Sincronizzazione non completata per un errore del database. I dati già presenti sono conservati; verifica il database e riprova.');
            }

            if ($acquired) {
                try {
                    GravityZoneSetting::query()->whereKey(1)->update(['companies_sync_error' => $exception->getMessage()]);
                } catch (QueryException) {
                    // The original sanitized error still reaches the caller when storage is unavailable.
                }
            }

            throw $exception;
        } finally {
            if ($acquired) {
                $lock->release();
            }
        }
    }
}
