<?php

namespace App\Integrations\GravityZone;

use App\Models\Company;
use App\Models\Endpoint;
use App\Models\GravityZoneSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class EndpointSynchronizer
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
                'endpoints_sync_attempted_at' => now(),
                'endpoints_sync_error' => 'L’ultimo tentativo non è ancora stato completato.',
            ])->save();

            $count = 0;
            $failures = 0;
            $firstError = null;
            $interruptedBy = null;
            $inventories = [];
            $owners = [];
            $conflicts = [];
            $companies = Company::query()->where('is_present', true)->orderBy('id')->get();

            foreach ($companies as $company) {
                $company->forceFill([
                    'endpoints_sync_attempted_at' => now(),
                    'endpoints_sync_error' => 'L’ultimo tentativo non è ancora stato completato.',
                ])->save();

                try {
                    if ($interruptedBy !== null) {
                        throw new GravityZoneException($interruptedBy);
                    }

                    $items = app(GravityZoneClient::class)->getNetworkInventoryItems($settings, $company->gravityzone_id);
                    $inventories[$company->id] = $items;

                    foreach ($items as $item) {
                        if (isset($owners[$item['id']]) && $owners[$item['id']] !== $company->id) {
                            $conflicts[$owners[$item['id']]] = true;
                            $conflicts[$company->id] = true;
                        }
                        $owners[$item['id']] = $company->id;
                    }
                } catch (GravityZoneException $exception) {
                    $failures++;
                    $firstError ??= $exception->getMessage();
                    $company->forceFill(['endpoints_sync_error' => $exception->getMessage()])->save();

                    if ($exception->stopSynchronization) {
                        $interruptedBy = $exception->getMessage();
                    }
                }
            }

            // Detect cross-company ambiguity before changing any endpoint ownership.
            foreach ($companies as $company) {
                if (! array_key_exists($company->id, $inventories)) {
                    continue;
                }

                if (isset($conflicts[$company->id])) {
                    $message = 'GravityZone ha restituito lo stesso endpoint per aziende diverse. I loro dati precedenti sono conservati; ripeti la sincronizzazione e verifica l’azienda di appartenenza.';
                    $company->forceFill(['endpoints_sync_error' => $message])->save();
                    $failures++;
                    $firstError ??= $message;

                    continue;
                }

                $items = $inventories[$company->id];
                $ids = array_column($items, 'id');
                $seenAt = now();
                DB::transaction(function () use ($items, $ids, $company, $seenAt): void {
                    $rows = array_map(fn (array $item): array => [
                        'gravityzone_id' => $item['id'],
                        'company_id' => $company->id,
                        'name' => $item['name'],
                        'platform' => $item['platform'],
                        'is_managed' => $item['is_managed'],
                        'is_isolated' => $item['is_isolated'],
                        'is_present' => true,
                        'last_seen_at' => $seenAt,
                    ], $items);

                    foreach (array_chunk($rows, 500) as $chunk) {
                        Endpoint::upsert($chunk, ['gravityzone_id'], [
                            'company_id', 'name', 'platform', 'is_managed', 'is_isolated', 'is_present', 'last_seen_at', 'updated_at',
                        ]);
                    }

                    Endpoint::query()->where('company_id', $company->id)->whereNotIn('gravityzone_id', $ids)->update(['is_present' => false]);
                    $company->forceFill(['endpoints_synced_at' => $seenAt, 'endpoints_sync_error' => null])->save();
                });
                $count += count($items);
            }

            if ($failures > 0) {
                throw new GravityZoneException("Import endpoint non completato per {$failures} aziende. {$firstError}");
            }

            $settings->forceFill(['endpoints_synced_at' => now(), 'endpoints_sync_error' => null])->save();

            return $count;
        } catch (GravityZoneException|QueryException $exception) {
            if ($exception instanceof QueryException) {
                $exception = new GravityZoneException('Import endpoint non completato per un errore del database. I dati locali sono conservati; verifica il database e riprova.');
            }

            if ($acquired) {
                try {
                    GravityZoneSetting::query()->whereKey(1)->update(['endpoints_sync_error' => $exception->getMessage()]);
                } catch (QueryException) {
                    // Preserve the sanitized failure even when storage is unavailable.
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
