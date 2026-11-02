<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Integrations\GravityZone\CompanySynchronizer;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\Company;
use App\Models\GravityZoneSetting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

class ManageCompanies extends ManageRecords
{
    protected static string $resource = CompanyResource::class;

    public function getSubheading(): ?string
    {
        $settings = GravityZoneSetting::query()->select([
            'companies_sync_attempted_at', 'companies_synced_at', 'companies_sync_error',
        ])->find(1);

        if (! $settings) {
            return 'GravityZone non configurato. Un amministratore deve salvare la connessione nella pagina Integrazione GravityZone.';
        }

        return 'Ultimo tentativo: '.($settings->companies_sync_attempted_at?->format('d/m/Y H:i') ?? 'mai')
            .'. Ultima lettura elenco riuscita: '.($settings->companies_synced_at?->format('d/m/Y H:i') ?? 'mai')
            .'.'.($settings->companies_sync_error ? ' '.$settings->companies_sync_error : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('synchronize')->label('Sincronizza aziende')->authorize('synchronize', Company::class)
                ->requiresConfirmation()->modalHeading('Sincronizzare le aziende da GravityZone?')
                ->modalDescription('Legge l’elenco aziende e le licenze tecniche. Conserva contatti e note locali e le aziende non più restituite. Non modifica GravityZone.')
                ->action(function (): void {
                    Gate::authorize('synchronize', Company::class);

                    try {
                        $count = app(CompanySynchronizer::class)->sync();
                        Notification::make()->success()->title('Sincronizzazione completata')
                            ->body('Aziende rilevate: '.$count.'. Contatti e note locali conservati.')->send();
                    } catch (GravityZoneException $exception) {
                        Notification::make()->danger()->title('Sincronizzazione non completata')
                            ->body($exception->getMessage())->send();
                    } catch (QueryException) {
                        Notification::make()->danger()->title('Sincronizzazione non completata')
                            ->body('Errore nel salvataggio locale. I dati esistenti sono conservati; riprova.')->send();
                    }
                }),
        ];
    }
}
