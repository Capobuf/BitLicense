<?php

namespace App\Filament\Actions;

use App\Models\Company;
use App\Models\GravityZoneSetting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class SynchronizeGravityZoneAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'synchronize';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Aggiorna da GravityZone')->icon(Heroicon::OutlinedArrowPath)->iconButton()
            ->tooltip('Aggiorna da GravityZone')->color('gray')->authorize('synchronize', Company::class)
            ->action(function (): void {
                Gate::authorize('synchronize', Company::class);

                try {
                    if (! GravityZoneSetting::whereKey(1)->whereNotNull('api_key')->whereNotNull('api_url')->exists()) {
                        Notification::make()->warning()->title('Configura prima GravityZone in Impostazioni')->send();

                        return;
                    }

                    $requested = Cache::lock('gravityzone-refresh', 3600)->get(
                        fn (): bool => Cache::add('gravityzone-sync-requested', true),
                    );

                    if (Cache::has('gravityzone-sync-requested')) {
                        session()->put('gravityzone-sync-requested-at', now()->getTimestamp());
                    }

                    Notification::make()->info()
                        ->title($requested ? 'Aggiornamento richiesto' : 'Aggiornamento già richiesto o in corso')
                        ->body('L’aggiornamento avviene in background. Attendi qualche minuto, poi ricarica la pagina per vedere i nuovi dati e l’esito.')
                        ->send();
                } catch (QueryException) {
                    Notification::make()->danger()->title('Richiesta non registrata')->body('Verifica la connessione al database e riprova.')->send();
                }
            });
    }

    /** Reports once, on page load, how the background update requested in this session ended. */
    public static function notifyRequestedOutcome(): void
    {
        $requestedAt = session('gravityzone-sync-requested-at');
        $settings = $requestedAt === null ? null : GravityZoneSetting::find(1);
        if ($settings === null || ! Gate::allows('synchronize', Company::class)) {
            return;
        }

        $since = fn ($attemptedAt): bool => $attemptedAt !== null && $attemptedAt->getTimestamp() >= $requestedAt;
        // Companies run first: their failure stops the update before computers are read.
        $error = match (true) {
            $since($settings->companies_sync_attempted_at) && filled($settings->companies_sync_error) => $settings->companies_sync_error,
            $since($settings->endpoints_sync_attempted_at) => $settings->endpoints_sync_error,
            default => GravityZoneSetting::SYNC_IN_PROGRESS,
        };
        if ($error === GravityZoneSetting::SYNC_IN_PROGRESS) {
            return;
        }

        session()->forget('gravityzone-sync-requested-at');
        if ($error === null) {
            Notification::make()->success()->title('Aggiornamento GravityZone completato')->send();
        } else {
            Notification::make()->danger()->title('Aggiornamento GravityZone non riuscito')->body($error)->persistent()->send();
        }
    }
}
