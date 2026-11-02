<?php

namespace App\Filament\Pages;

use App\Integrations\GravityZone\GravityZoneClient;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\GravityZoneSetting;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Database\QueryException;

class GravityZoneSettings extends Page
{
    protected static ?string $title = 'Integrazione GravityZone';

    protected static ?string $slug = 'gravityzone';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloudArrowDown;

    protected string $view = 'filament.pages.gravity-zone-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdministrator() ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill([
            'api_url' => GravityZoneSetting::find(1)?->api_url,
            'api_key' => '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Connessione API')
                ->description('Una sola chiave per BitLicense. L’integrazione consente esclusivamente operazioni di lettura.')
                ->schema([
                    TextInput::make('api_url')->label('URL base API')->required()->url()->maxLength(2048)
                        ->placeholder('https://cloud.gravityzone.bitdefender.com/api')
                        ->helperText('Copia l’URL API della tua console GravityZone, fino a /api. Usa HTTPS.')
                        ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $parts = is_string($value) ? parse_url($value) : false;

                            if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                                || ! str_ends_with(rtrim($parts['path'] ?? '', '/'), '/api')
                                || isset($parts['user']) || isset($parts['pass'])
                                || isset($parts['query']) || isset($parts['fragment'])) {
                                $fail('Inserisci un URL HTTPS che termina con /api, senza credenziali, parametri o frammenti.');
                            }
                        }]),
                    TextInput::make('api_key')->label('API key')->password()->autocomplete('new-password')
                        ->required(fn (): bool => ! $this->hasApiKey())
                        ->rules(['nullable', 'string'])->maxLength(4096)
                        ->helperText('La chiave salvata non viene mostrata. Lascia vuoto per conservarla; compila il campo per sostituirla.'),
                ]),
        ]);
    }

    public function hasApiKey(): bool
    {
        return GravityZoneSetting::query()->whereKey(1)->exists();
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $state = $this->form->getState();

            if (blank($state['api_key'] ?? null)) {
                unset($state['api_key']);
            }

            $state['api_url'] = rtrim($state['api_url'], '/');
            GravityZoneSetting::query()->updateOrCreate(['id' => 1], $state);
            Notification::make()->success()->title('Configurazione salvata')
                ->body('Il salvataggio non esegue chiamate a GravityZone.')->send();
        } catch (QueryException|EncryptException) {
            Notification::make()->danger()->title('Salvataggio non riuscito')
                ->body('La configurazione non è stata salvata. Riprova o verifica il database di sviluppo.')->send();
        } finally {
            $this->data['api_key'] = '';
        }
    }

    public function testConnection(): void
    {
        abort_unless(static::canAccess(), 403);
        $settings = GravityZoneSetting::find(1);

        if (! $settings) {
            Notification::make()->warning()->title('Salva prima la configurazione')->send();

            return;
        }

        try {
            app(GravityZoneClient::class)->getCompaniesList($settings);
            Notification::make()->success()->title('Connessione verificata')
                ->body('La lettura delle aziende è riuscita. Nessun dato è stato importato o modificato.')->send();
        } catch (GravityZoneException $exception) {
            Notification::make()->danger()->title('Connessione non riuscita')
                ->body($exception->getMessage())->send();
        }
    }

    public function dehydrate(): void
    {
        // Never serialize even a newly entered key into a Livewire response.
        $this->data['api_key'] = '';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')->label('Verifica connessione')
                ->requiresConfirmation()
                ->modalHeading('Verificare la connessione GravityZone?')
                ->modalDescription('Verrà eseguita una lettura delle aziende con la configurazione salvata. Nessuna modifica su GravityZone.')
                ->action(fn () => $this->testConnection()),
        ];
    }
}
