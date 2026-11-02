<?php

namespace App\Filament\Resources\Coverages;

use App\Filament\Resources\Coverages\Pages\ManageCoverages;
use App\Models\Company;
use App\Models\Coverage;
use App\Models\CoverageAssignment;
use App\Models\Workstation;
use App\Services\CoverageService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CoverageResource extends Resource
{
    protected static ?string $model = Coverage::class;

    protected static ?string $slug = 'coperture';

    protected static ?string $modelLabel = 'copertura commerciale';

    protected static ?string $pluralModelLabel = 'coperture commerciali';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Copertura commerciale acquistata')->columnSpanFull()->columns(2)->schema([
                TextEntry::make('company.name')->label('Azienda'),
                TextEntry::make('workstation.name')->label('Postazione assegnata')
                    ->formatStateUsing(fn (Coverage $record, string $state): string => '#'.$record->workstation_id.' · '.$state),
                TextEntry::make('starts_on')->label('Primo giorno coperto')->date(),
                TextEntry::make('ends_on')->label('Ultimo giorno coperto, incluso')->date(),
                TextEntry::make('price_cents')->label('Importo della copertura')->money('EUR', divideBy: 100),
                TextEntry::make('invoice_reference')->label('Riferimento fattura')->placeholder('Non indicato'),
                TextEntry::make('quote_reference')->label('Riferimento preventivo')->placeholder('Non indicato'),
            ]),
            Section::make('Licenza tecnica GravityZone')->columnSpanFull()
                ->description('La scadenza tecnica aziendale è distinta dal periodo commerciale acquistato per questa postazione.')
                ->schema([
                    TextEntry::make('company.technical_expires_on')->label('Scadenza tecnica aziendale')->date()->placeholder('Non disponibile'),
                ]),
            RepeatableEntry::make('assignments')->label('Storico delle assegnazioni')->columnSpanFull()->columns(3)
                ->state(fn (Coverage $record) => $record->assignments()->with('workstation')->get())
                ->schema([
                    TextEntry::make('workstation.name')->label('Postazione')
                        ->formatStateUsing(fn (CoverageAssignment $record, string $state): string => '#'.$record->workstation_id.' · '.$state),
                    TextEntry::make('assigned_at')->label('Assegnata il')->dateTime(),
                    TextEntry::make('released_at')->label('Trasferita il')->dateTime()->placeholder('Assegnazione attuale'),
                ]),
        ]);
    }

    public static function periodFields(): array
    {
        return [
            DatePicker::make('starts_on')->label('Primo giorno coperto')->required()->native(false)->displayFormat('d/m/Y'),
            DatePicker::make('ends_on')->label('Ultimo giorno coperto, incluso')->required()->native(false)->displayFormat('d/m/Y')
                ->afterOrEqual('starts_on')->helperText('Il periodo successivo può iniziare dal giorno seguente.'),
            TextInput::make('amount')->label('Importo per postazione')->prefix('€')->inputMode('decimal')->required()
                ->rules(['string', 'regex:/^\d{1,9}(?:[.,]\d{1,2})?$/D'])->maxLength(12)
                ->validationMessages(['regex' => 'Inserisci un importo non negativo, senza separatori delle migliaia e con al massimo due decimali.'])
                ->helperText('Importo per ciascuna postazione selezionata, con eventuali add-on inclusi. Usa la virgola o il punto per i decimali.'),
            TextInput::make('invoice_reference')->label('Riferimento fattura')->maxLength(255),
            TextInput::make('quote_reference')->label('Riferimento preventivo')->maxLength(255),
        ];
    }

    public static function createAction(): Action
    {
        return Action::make('create')->label('Nuova copertura')->modalHeading('Nuova copertura commerciale')->modalSubmitActionLabel('Crea coperture')
            ->authorize('create', Coverage::class)->schema([
                Select::make('company_id')->label('Azienda')->required()->searchable()->live()
                    ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->afterStateUpdated(fn (Set $set) => $set('workstation_ids', [])),
                Select::make('workstation_ids')->label('Postazioni')->multiple()->searchable()->required()->minItems(1)
                    ->options(fn (Get $get): array => Workstation::query()->where('company_id', $get('company_id'))->orderBy('name')->get(['id', 'name'])
                        ->mapWithKeys(fn (Workstation $workstation): array => [$workstation->id => '#'.$workstation->id.' · '.$workstation->name])->all())
                    ->helperText('Una copertura distinta per ogni postazione, con lo stesso periodo e importo per ciascuna. Nessuna data viene ricavata da GravityZone.'),
                ...static::periodFields(),
            ])->action(function (array $data, Schema $schema): void {
                Gate::authorize('create', Coverage::class);
                try {
                    $created = app(CoverageService::class)->create($data);
                } catch (ValidationException $exception) {
                    static::formErrors($exception, $schema);
                } catch (QueryException|ModelNotFoundException) {
                    static::storageFailure();

                    return;
                }
                Notification::make()->success()->title('Coperture create')->body('Coperture individuali registrate: '.$created->count().'.')->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table->stackedOnMobile()->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['company', 'workstation']))
            ->columns([
                TextColumn::make('workstation.name')->label('Postazione')->searchable()->wrap()
                    ->description(fn (Coverage $record): string => '#'.$record->workstation_id.' · '.$record->company->name),
                TextColumn::make('starts_on')->label('Inizio commerciale')->date()->sortable(),
                TextColumn::make('ends_on')->label('Fine commerciale, inclusa')->date()->sortable(),
                TextColumn::make('price_cents')->label('Importo')->money('EUR', divideBy: 100)->sortable(),
                TextColumn::make('invoice_reference')->label('Riferimento fattura')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quote_reference')->label('Riferimento preventivo')->searchable()->toggleable(isToggledHiddenByDefault: true),
            ])->filters([SelectFilter::make('company')->label('Azienda')->relationship('company', 'name')->searchable()->preload()])
            ->recordActions([ActionGroup::make([
                ViewAction::make()->label('Periodo e storico')->modalHeading('Copertura commerciale e assegnazioni'),
                Action::make('edit')->label('Correggi copertura')->modalHeading('Correggi copertura commerciale')->modalSubmitActionLabel('Salva correzioni')->authorize('update')
                    ->beforeFormFilled(fn (Coverage $record, ManageCoverages $livewire) => $livewire->expectedAssignments = [$record->id => $record->workstation_id])
                    ->fillForm(fn (Coverage $record): array => [
                        'starts_on' => $record->starts_on->toDateString(), 'ends_on' => $record->ends_on->toDateString(),
                        'amount' => intdiv($record->price_cents, 100).'.'.str_pad((string) ($record->price_cents % 100), 2, '0', STR_PAD_LEFT),
                        'invoice_reference' => $record->invoice_reference, 'quote_reference' => $record->quote_reference,
                    ])->schema([
                        TextEntry::make('company.name')->label('Azienda'),
                        TextEntry::make('workstation.name')->label('Postazione assegnata'),
                        ...static::periodFields(),
                    ])->action(function (Coverage $record, array $data, Schema $schema, ManageCoverages $livewire): void {
                        Gate::authorize('update', $record);
                        try {
                            app(CoverageService::class)->update(static::expectedCoverage($record, $livewire), $data);
                        } catch (ValidationException $exception) {
                            static::formErrors($exception, $schema, ['target_workstation_id' => 'starts_on']);
                        } catch (QueryException|ModelNotFoundException) {
                            static::storageFailure();

                            return;
                        }
                        Notification::make()->success()->title('Copertura aggiornata')->send();
                    }),
                Action::make('transfer')->label('Trasferisci a un altro PC')->modalHeading('Trasferisci la copertura a una postazione sostitutiva')
                    ->modalSubmitActionLabel('Conferma trasferimento')->authorize('transfer')->requiresConfirmation()
                    ->modalDescription('Il trasferimento conserva periodo acquistato, importo e riferimenti. La precedente assegnazione rimane nello storico. Non modifica GravityZone.')
                    ->beforeFormFilled(fn (Coverage $record, ManageCoverages $livewire) => $livewire->expectedAssignments = [$record->id => $record->workstation_id])
                    ->schema([
                        TextEntry::make('workstation.name')->label('Postazione attuale'),
                        TextEntry::make('purchased_period')->label('Periodo acquistato')
                            ->state(fn (Coverage $record): string => $record->starts_on->format('d/m/Y').' – '.$record->ends_on->format('d/m/Y').' (ultimo giorno incluso)'),
                        TextEntry::make('price_cents')->label('Importo conservato')->money('EUR', divideBy: 100),
                        Select::make('target_workstation_id')->label('Postazione sostitutiva')->required()->searchable()
                            ->options(fn (Coverage $record): array => Workstation::query()->where('company_id', $record->company_id)->whereKeyNot($record->workstation_id)->orderBy('name')->get(['id', 'name'])
                                ->mapWithKeys(fn (Workstation $workstation): array => [$workstation->id => '#'.$workstation->id.' · '.$workstation->name])->all()),
                    ])->action(function (Coverage $record, array $data, Schema $schema, ManageCoverages $livewire): void {
                        Gate::authorize('transfer', $record);
                        try {
                            app(CoverageService::class)->transfer(static::expectedCoverage($record, $livewire), Workstation::query()->findOrFail($data['target_workstation_id']));
                        } catch (ValidationException $exception) {
                            static::formErrors($exception, $schema, ['starts_on' => 'target_workstation_id']);
                        } catch (QueryException|ModelNotFoundException) {
                            static::storageFailure();

                            return;
                        }
                        Notification::make()->success()->title('Copertura trasferita')->send();
                    }),
            ])->label('Azioni')])->defaultSort('ends_on')->emptyStateHeading('Nessuna copertura commerciale trovata')
            ->emptyStateDescription('Registra il periodo acquistato per una o più postazioni. La scadenza tecnica GravityZone resta separata.');
    }

    private static function expectedCoverage(Coverage $record, ManageCoverages $livewire): Coverage
    {
        if (! isset($livewire->expectedAssignments[$record->id])) {
            throw ValidationException::withMessages(['target_workstation_id' => 'Riapri la copertura prima di procedere.']);
        }

        $expected = clone $record;
        $expected->workstation_id = $livewire->expectedAssignments[$record->id];

        return $expected;
    }

    private static function formErrors(ValidationException $exception, Schema $schema, array $fields = []): never
    {
        $errors = [];
        foreach ($exception->errors() as $key => $messages) {
            $errors[$schema->getStatePath().'.'.($fields[$key] ?? $key)] = $messages;
        }
        throw ValidationException::withMessages($errors);
    }

    private static function storageFailure(): void
    {
        Notification::make()->danger()->title('Operazione non completata')
            ->body('Il salvataggio non è riuscito. Ricarica i dati e riprova; nessuna conferma è stata registrata.')->send();
    }

    public static function getPages(): array
    {
        return ['index' => ManageCoverages::route('/')];
    }
}
