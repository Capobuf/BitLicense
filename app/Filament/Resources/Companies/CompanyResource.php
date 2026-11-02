<?php

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Models\Company;
use BackedEnum;
use Filament\Actions\EditAction;
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
            ->recordActions([EditAction::make()->label('Contatti e dettagli')->modalHeading(fn (Company $record): string => $record->name)])
            ->emptyStateHeading('Nessuna azienda trovata')
            ->emptyStateDescription('Controlla ricerca e filtri. Per importare le aziende, un amministratore può usare Sincronizza aziende.')->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageCompanies::route('/')];
    }
}
