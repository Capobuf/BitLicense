<?php

namespace App\Filament\Resources\Endpoints;

use App\Filament\Resources\Endpoints\Pages\ManageEndpoints;
use App\Models\Endpoint;
use App\Models\Workstation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EndpointResource extends Resource
{
    protected static ?string $model = Endpoint::class;

    protected static ?string $slug = 'endpoint';

    protected static ?string $modelLabel = 'endpoint GravityZone';

    protected static ?string $pluralModelLabel = 'endpoint GravityZone';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('name')->label('Nome tecnico GravityZone'),
            TextEntry::make('gravityzone_id')->label('ID GravityZone')->wrap(),
            TextEntry::make('company.name')->label('Azienda GravityZone'),
            TextEntry::make('platform')->label('Piattaforma')->placeholder('Non disponibile'),
            TextEntry::make('is_managed')->label('Gestito')->placeholder('Non disponibile')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Sì' : 'No'),
            TextEntry::make('is_isolated')->label('Isolato')->placeholder('Non disponibile')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Sì' : 'No'),
            TextEntry::make('is_present')->label('Presenza nell’ultima lettura')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Rilevato' : 'Non rilevato'),
            TextEntry::make('last_seen_at')->label('Ultimo rilevamento')->dateTime(),
            TextEntry::make('workstation.name')->label('Postazione locale')->placeholder('Da verificare: nessuna associazione'),
            TextEntry::make('workstation.company.name')->label('Azienda della postazione locale')->placeholder('Nessuna associazione'),
            TextEntry::make('company_mismatch')->label('Associazione da verificare')->columnSpanFull()
                ->state('L’endpoint risulta in un’altra azienda GravityZone. La postazione locale e i suoi dati sono stati conservati; verifica prima di procedere.')
                ->visible(fn (Endpoint $record): bool => $record->workstation !== null && $record->workstation->company_id !== $record->company_id),
            TextEntry::make('company.endpoints_synced_at')->label('Ultima lettura endpoint azienda')->dateTime()->placeholder('Mai riuscita'),
            TextEntry::make('company.endpoints_sync_error')->label('Errore lettura azienda')->placeholder('Nessun errore')
                ->helperText('In caso di errore i dati mostrati sono gli ultimi disponibili.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['company', 'workstation.company']))
            ->columns([
                TextColumn::make('name')->label('Endpoint')->searchable()->sortable()->wrap()
                    ->description(fn (Endpoint $record): string => $record->company->name),
                TextColumn::make('platform')->label('Piattaforma')->placeholder('Non disponibile')->wrap(),
                TextColumn::make('is_managed')->label('Gestito')->placeholder('Non disponibile')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Sì' : 'No')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('is_isolated')->label('Isolato')->placeholder('Non disponibile')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Sì' : 'No')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('review')->label('Verifica')->badge()
                    ->state(fn (Endpoint $record): string => $record->workstation === null || $record->workstation->company_id !== $record->company_id ? 'Da verificare' : 'Collegato')
                    ->color(fn (Endpoint $record): string => $record->workstation === null || $record->workstation->company_id !== $record->company_id ? 'warning' : 'success')
                    ->description(fn (Endpoint $record): ?string => $record->workstation?->name)->wrap(),
                TextColumn::make('is_present')->label('Presenza')->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Rilevato' : 'Non rilevato')
                    ->color(fn (bool $state): string => $state ? 'success' : 'warning')
                    ->tooltip(fn (Endpoint $record): string => 'Ultimo rilevamento: '.$record->last_seen_at->format('d/m/Y H:i')),
            ])
            ->filters([
                SelectFilter::make('company')->label('Azienda')->relationship('company', 'name')->searchable()->preload(),
                TernaryFilter::make('is_present')->label('Presenza')->trueLabel('Rilevato')->falseLabel('Non rilevato'),
                Filter::make('needs_review')->label('Da verificare')->query(fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query
                    ->whereDoesntHave('workstation')->orWhereHas('workstation', fn (Builder $query): Builder => $query->whereColumn('workstations.company_id', '!=', 'endpoints.company_id')))),
            ])
            ->recordActions([ActionGroup::make([
                ViewAction::make()->label('Dettagli'),
                Action::make('link')->label('Associa postazione')->modalSubmitActionLabel('Associa postazione')->authorize('link')
                    ->modalDescription('Scegli esplicitamente una postazione libera della stessa azienda. Il nome da solo non identifica una postazione. Non viene creata alcuna copertura commerciale.')
                    ->schema([
                        Select::make('workstation_id')->label('Postazione locale')->required()->searchable()
                            ->options(fn (Endpoint $record): array => Workstation::query()->where('company_id', $record->company_id)->whereNull('endpoint_id')->orderBy('name')->get(['id', 'name'])
                                ->mapWithKeys(fn (Workstation $workstation): array => [$workstation->id => '#'.$workstation->id.' · '.$workstation->name])->all()),
                    ])
                    ->action(function (Endpoint $record, array $data, Schema $schema): void {
                        Gate::authorize('link', $record->fresh(['company', 'workstation']));
                        try {
                            Workstation::query()->findOrFail($data['workstation_id'])->linkEndpoint($record);
                        } catch (ValidationException $exception) {
                            throw ValidationException::withMessages([$schema->getStatePath().'.workstation_id' => $exception->errors()['workstation_id']]);
                        }
                        Notification::make()->success()->title('Postazione associata')->send();
                    }),
                Action::make('createWorkstation')->label('Crea postazione')->modalSubmitActionLabel('Crea e associa')->authorize('link')
                    ->modalDescription('Crea una postazione locale per questa azienda e la associa all’endpoint. Nessuna copertura commerciale viene attivata.')
                    ->schema([TextInput::make('name')->label('Nome locale')->default(fn (Endpoint $record): string => $record->name)->required()->maxLength(255)])
                    ->action(function (Endpoint $record, array $data, Schema $schema): void {
                        Gate::authorize('link', $record->fresh(['company', 'workstation']));
                        Gate::authorize('create', Workstation::class);
                        try {
                            DB::transaction(function () use ($record, $data): void {
                                Workstation::query()->create(['company_id' => $record->company_id, 'name' => $data['name']])->linkEndpoint($record);
                            });
                        } catch (ValidationException $exception) {
                            throw ValidationException::withMessages([$schema->getStatePath().'.name' => $exception->errors()['workstation_id']]);
                        }
                        Notification::make()->success()->title('Postazione creata e associata')->send();
                    }),
            ])->label('Azioni')])->defaultSort('name')->emptyStateHeading('Nessun endpoint trovato')
            ->emptyStateDescription('Controlla ricerca e filtri. Un amministratore può sincronizzare gli endpoint delle aziende già importate.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageEndpoints::route('/')];
    }
}
