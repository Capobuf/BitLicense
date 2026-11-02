<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Filament\Resources\Workstations\WorkstationResource;
use App\Models\Company;
use App\Support\Euro;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'aziende';

    protected static ?string $modelLabel = 'azienda';

    protected static ?string $pluralModelLabel = 'aziende';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Prodotti e prezzi annuali abituali')->columnSpanFull()
                ->description('Voci commerciali locali per questa azienda. Il prezzo annuo comprende gli add-on ed è proposto per le nuove coperture; cambiare il listino non modifica i periodi già acquistati.')
                ->schema([
                    Repeater::make('products')->label('Prodotti aziendali')->relationship()->defaultItems(0)
                        ->addActionLabel('Aggiungi prodotto')->deletable(false)->reorderable(false)->columns(2)
                        ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => ['name' => $data['name'], 'annual_price_cents' => Euro::fromCents($data['annual_price_cents'])])
                        ->saveRelationshipsUsing(function (Repeater $component): void {
                            $existing = $component->getCachedExistingRecords()->keys()->all();
                            $submitted = array_keys($component->getState() ?? []);
                            $recordKeys = array_filter($submitted, fn ($key): bool => str_starts_with((string) $key, 'record-'));

                            // Filament deletes omitted relationship rows even when the delete button is disabled.
                            if (array_diff($existing, $submitted) || array_diff($recordKeys, $existing)) {
                                throw ValidationException::withMessages([$component->getStatePath() => 'I prodotti esistenti non possono essere rimossi o spostati tra aziende. Ricarica la scheda.']);
                            }
                            $component->saveToRelationship();
                        })
                        ->schema([
                            TextInput::make('name')->label('Prodotto o edizione')->required()->maxLength(255),
                            TextInput::make('annual_price_cents')->label('Prezzo annuo abituale')->prefix('€')->inputMode('decimal')
                                ->required()->rules(['string', 'regex:'.Euro::PATTERN])->maxLength(12)
                                ->validationMessages(['regex' => 'Inserisci un importo non negativo, senza separatori delle migliaia e con al massimo due decimali.'])
                                ->dehydrateStateUsing(fn (string $state): int => Euro::toCents($state)),
                        ]),
                ]),
            Section::make('Dati GravityZone')->columnSpanFull()->columns(2)
                ->description('Licenza principale GravityZone in sola lettura: non è la copertura commerciale delle postazioni. Le eventuali licenze aggiuntive non sono aggregate.')
                ->schema([
                    TextEntry::make('name')->label('Azienda'),
                    TextEntry::make('technical_license_type')->label('Tipo licenza tecnica')->placeholder('Non disponibile'),
                    TextEntry::make('technical_expires_on')->label('Scadenza tecnica aziendale')->date()->placeholder('Non disponibile'),
                    TextEntry::make('license_synced_at')->label('Ultima lettura licenza')->dateTime()->placeholder('Mai riuscita'),
                    TextEntry::make('last_seen_at')->label('Ultima presenza in GravityZone')->dateTime(),
                    TextEntry::make('is_present')->label('Presenza in GravityZone')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Presente' : 'Non restituita dall’ultima sincronizzazione'),
                    TextEntry::make('sync_error')->label('Errore lettura licenza')->columnSpanFull()
                        ->visible(fn (Company $record): bool => filled($record->sync_error))
                        ->helperText('I dati della licenza mostrati sono gli ultimi disponibili e potrebbero non essere aggiornati.'),
                ]),
            Section::make('Contatti e note locali')->columnSpanFull()->columns(2)
                ->description('Questi dati vengono conservati durante la sincronizzazione.')
                ->schema([
                    TextInput::make('contact_name')->label('Referente')->maxLength(255),
                    TextInput::make('contact_email')->label('Email')->email()->maxLength(255),
                    TextInput::make('contact_phone')->label('Telefono')->tel()->maxLength(255),
                    Textarea::make('notes')->label('Note')->rows(3)->maxLength(10000)->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')->label('Azienda')->wrap()
                    ->searchable(['name', 'contact_name', 'contact_email', 'contact_phone'])->sortable()
                    ->description(fn (Company $record): ?string => $record->contact_name ?: $record->contact_email),
                TextColumn::make('technical_expires_on')->label('Scadenza tecnica aziendale')->date()->placeholder('Non disponibile')
                    ->description(fn (Company $record): string => filled($record->sync_error)
                        ? 'Ultimo dato disponibile · lettura non riuscita'
                        : 'GravityZone · distinta dalla copertura commerciale')->wrap()->sortable(),
                TextColumn::make('sync_status')->label('Sincronizzazione')->badge()
                    ->state(fn (Company $record): string => ! $record->is_present ? 'Non restituita' : (filled($record->sync_error) ? 'Errore licenza' : 'Aggiornata'))
                    ->color(fn (Company $record): string => ! $record->is_present || filled($record->sync_error) ? 'warning' : 'success')
                    ->tooltip(fn (Company $record): ?string => $record->sync_error ?: (! $record->is_present ? 'Conservata con i suoi dati locali.' : null)),
                TextColumn::make('last_seen_at')->label('Ultima presenza')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_present')->label('Presenza in GravityZone')->trueLabel('Presente')->falseLabel('Non restituita'),
                Filter::make('sync_error')->label('Con errori di lettura licenza')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('sync_error')),
            ])
            ->recordActions([ActionGroup::make([
                EditAction::make()->label('Scheda azienda')->databaseTransaction()->modalHeading(fn (Company $record): string => $record->name),
                Action::make('workstations')->label('Postazioni dell’azienda')
                    ->url(fn (Company $record): string => WorkstationResource::getUrl('index', ['filters' => ['company' => ['value' => $record->id]]])),
            ])->label('Azioni')])
            ->emptyStateHeading('Nessuna azienda trovata')
            ->emptyStateDescription('Controlla ricerca e filtri. Per importare le aziende, un amministratore può usare Sincronizza aziende.')->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompanies::route('/')];
    }
}
