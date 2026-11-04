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

                    Notification::make()->info()
                        ->title($requested ? 'Aggiornamento richiesto' : 'Aggiornamento già richiesto o in corso')
                        ->body('L’aggiornamento avviene in background. Attendi qualche minuto, poi ricarica la pagina per vedere i nuovi dati.')
                        ->send();
                } catch (QueryException) {
                    Notification::make()->danger()->title('Richiesta non registrata')->body('Verifica la connessione al database e riprova.')->send();
                }
            });
    }
}
