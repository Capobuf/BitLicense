<?php

namespace App\Filament\Resources\Workstations;

use App\Filament\Resources\Workstations\Pages\ManageWorkstations;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\Coverage;
use App\Models\Workstation;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rules\Exists;

class WorkstationResource extends Resource
{
    protected static ?string $model = Workstation::class;

    protected static ?string $slug = 'postazioni';

    protected static ?string $modelLabel = 'postazione';

    protected static ?string $pluralModelLabel = 'postazioni';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dati commerciali locali')->columnSpanFull()->columns(2)
                ->description('Questi dati sono indipendenti dalla sincronizzazione GravityZone.')
                ->schema([
                    Select::make('company_id')->label('Azienda')->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->required()->searchable()->exists(Company::class, 'id')->live()
                        ->afterStateUpdated(fn (Set $set) => $set('company_product_id', null))
                        ->disabled(fn (string $operation): bool => $operation === 'edit')->saved(fn (string $operation): bool => $operation === 'create'),
                    TextInput::make('name')->label('Nome locale')->required()->maxLength(255),
                    Select::make('company_product_id')->label('Prodotto o edizione aziendale')->searchable()->nullable()
                        ->options(fn (Get $get, ?Workstation $record): array => CompanyProduct::query()->where('company_id', $record?->company_id ?? $get('company_id'))->orderBy('name')->pluck('name', 'id')->all())
                        ->exists(CompanyProduct::class, 'id', modifyRuleUsing: fn (Exists $rule, Get $get, ?Workstation $record): Exists => $rule->where('company_id', $record?->company_id ?? $get('company_id')))
                        ->helperText('Gestisci i prodotti e i prezzi annuali abituali nella scheda azienda. Puoi anche descrivere il prodotto liberamente qui sotto.'),
                    DatePicker::make('installed_on')->label('Data di installazione / attivazione')->native(false)->displayFormat('d/m/Y')->nullable(),
                    Textarea::make('commercial_description')->label('Descrizione commerciale')->maxLength(10000)->rows(3)->columnSpanFull()
                        ->helperText('Descrivi il prodotto quando il dato tecnico non è disponibile. Gli add-on sono compresi nel prezzo totale della copertura.'),
                    Textarea::make('notes')->label('Note')->maxLength(10000)->rows(3)->columnSpanFull(),
                ]),
            Section::make('Dati tecnici GravityZone')->columnSpanFull()->columns(2)
                ->visible(fn (string $operation): bool => $operation === 'edit')
                ->description('Sola lettura. La scadenza tecnica della licenza aziendale è distinta dai periodi commerciali acquistati per la postazione.')
                ->schema([
                    TextEntry::make('endpoint.name')->label('Nome tecnico GravityZone')->placeholder('Postazione manuale, nessun endpoint associato'),
                    TextEntry::make('endpoint.company.name')->label('Azienda GravityZone')->placeholder('Nessun endpoint associato'),
                    TextEntry::make('company.technical_expires_on')->label('Scadenza tecnica aziendale')->date()->placeholder('Non disponibile')
                        ->helperText('Licenza tecnica dell’azienda locale; non indica la copertura commerciale del PC.'),
                    TextEntry::make('association_warning')->label('Associazione da verificare')->columnSpanFull()
                        ->state('L’endpoint risulta in un’altra azienda GravityZone. I dati della postazione locale sono conservati.')
                        ->visible(fn (?Workstation $record): bool => $record?->endpoint !== null && $record->company_id !== $record->endpoint->company_id),
                ]),
            Section::make('Coperture commerciali e storico')->columnSpanFull()
                ->visible(fn (string $operation): bool => $operation === 'edit')
                ->description('Periodi acquistati e riferimenti conservati, comprese le coperture trasferite. Per correggerli o trasferirli usa Coperture commerciali.')
                ->schema([
                    RepeatableEntry::make('commercial_coverages')->hiddenLabel()->columns(2)->placeholder('Nessuna copertura commerciale registrata')
                        ->state(fn (?Workstation $record) => $record ? Coverage::query()->whereHas('assignments', fn (Builder $query): Builder => $query->where('workstation_id', $record->id))->with('workstation')->orderBy('starts_on')->get() : [])
                        ->schema([
                            TextEntry::make('id')->label('Copertura #'),
                            TextEntry::make('workstation.name')->label('Assegnata attualmente a')
                                ->formatStateUsing(fn (Coverage $record, string $state): string => '#'.$record->workstation_id.' · '.$state),
                            TextEntry::make('starts_on')->label('Inizio commerciale')->date(),
                            TextEntry::make('ends_on')->label('Fine commerciale, inclusa')->date(),
                            TextEntry::make('price_cents')->label('Importo acquistato')->money('EUR', divideBy: 100),
                            TextEntry::make('invoice_reference')->label('Riferimento fattura')->placeholder('Non indicato'),
                            TextEntry::make('quote_reference')->label('Riferimento preventivo')->placeholder('Non indicato'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['company', 'endpoint.company']))
            ->columns([
                TextColumn::make('name')->label('Nome locale')->searchable(['name', 'commercial_description'])->sortable()->wrap()
                    ->description(fn (Workstation $record): string => '#'.$record->id.' · '.$record->company->name),
                TextColumn::make('endpoint.name')->label('Nome tecnico GravityZone')->searchable()->placeholder('Postazione manuale')->wrap(),
                TextColumn::make('association')->label('Associazione')->badge()
                    ->state(fn (Workstation $record): string => $record->endpoint === null ? 'Manuale' : ($record->company_id !== $record->endpoint->company_id ? 'Da verificare: azienda diversa' : ($record->endpoint->is_present ? 'Endpoint rilevato' : 'Endpoint non rilevato')))
                    ->color(fn (Workstation $record): string => $record->endpoint !== null && (! $record->endpoint->is_present || $record->company_id !== $record->endpoint->company_id) ? 'warning' : 'gray')->wrap(),
            ])
            ->filters([SelectFilter::make('company')->label('Azienda')->relationship('company', 'name')->searchable()->preload()])
            ->recordActions([EditAction::make()->label('Scheda postazione')->modalHeading(fn (Workstation $record): string => $record->name)
                ->mutateDataUsing(fn (array $data): array => Arr::only($data, ['name', 'notes', 'company_product_id', 'commercial_description', 'installed_on']))])
            ->emptyStateHeading('Nessuna postazione trovata')->emptyStateDescription('Crea una postazione manuale oppure associala da Endpoint GravityZone.')->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ManageWorkstations::route('/')];
    }
}
