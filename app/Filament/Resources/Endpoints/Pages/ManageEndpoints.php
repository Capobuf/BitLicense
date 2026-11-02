<?php

namespace App\Filament\Resources\Endpoints\Pages;

use App\Filament\Resources\Endpoints\EndpointResource;
use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\Company;
use App\Models\Endpoint;
use App\Models\GravityZoneSetting;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

class ManageEndpoints extends ManageRecords
{
    protected static string $resource = EndpointResource::class;

    public function getSubheading(): ?string
    {
        $settings = GravityZoneSetting::query()->select(['endpoints_sync_attempted_at', 'endpoints_synced_at', 'endpoints_sync_error'])->find(1);

        if (! $settings) {
            return 'GravityZone non configurato. Un amministratore deve configurare la connessione e importare le aziende.';
        }

        return 'Ultimo tentativo: '.($settings->endpoints_sync_attempted_at?->format('d/m/Y H:i') ?? 'mai')
            .'. Ultima sincronizzazione completa: '.($settings->endpoints_synced_at?->format('d/m/Y H:i') ?? 'mai')
            .'.'.($settings->endpoints_sync_error ? ' '.$settings->endpoints_sync_error : '')
            .' Gli endpoint nuovi restano da verificare: nessuna copertura commerciale automatica.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncErrors')->label('Errori aziende')->color('warning')
                ->visible(fn (): bool => Company::query()->whereNotNull('endpoints_sync_error')->exists())
                ->modalHeading('Errori di lettura endpoint per azienda')->modalSubmitAction(false)->modalCancelActionLabel('Chiudi')
                ->schema([
                    TextEntry::make('errors')->hiddenLabel()->listWithLineBreaks()->state(fn (): array => Company::query()->whereNotNull('endpoints_sync_error')->orderBy('name')->get(['name', 'endpoints_sync_error'])
                        ->map(fn (Company $company): string => $company->name.': '.$company->endpoints_sync_error)->all()),
                ]),
            Action::make('synchronize')->label('Sincronizza endpoint')->authorize('synchronize', Endpoint::class)
                ->requiresConfirmation()->modalHeading('Sincronizzare gli endpoint da GravityZone?')
                ->modalDescription('Legge gli endpoint delle aziende già importate. Conserva postazioni locali, associazioni e storico. Non modifica GravityZone e non crea coperture commerciali.')
                ->action(function (): void {
                    Gate::authorize('synchronize', Endpoint::class);

                    try {
                        $count = app(EndpointSynchronizer::class)->sync();
                        Notification::make()->success()->title('Sincronizzazione endpoint completata')->body('Endpoint rilevati: '.$count.'.')->send();
                    } catch (GravityZoneException $exception) {
                        Notification::make()->danger()->title('Sincronizzazione endpoint non completata')->body($exception->getMessage())->send();
                    } catch (QueryException) {
                        Notification::make()->danger()->title('Sincronizzazione endpoint non completata')->body('Errore nel salvataggio locale. Riprova; le postazioni locali sono conservate.')->send();
                    }
                }),
        ];
    }
}
