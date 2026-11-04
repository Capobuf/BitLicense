<?php

namespace App\Livewire;

use App\Models\Company;
use App\Models\CompanyRenewal;
use App\Models\Computer;
use App\Support\Euro;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CompanyRenewals extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    #[Locked]
    public int $companyId;

    #[Locked]
    public ?int $computerId = null;

    #[Locked]
    public array $expectedVersions = [];

    #[Locked]
    public array $expectedComputerVersions = [];

    #[Locked]
    public bool $linkingLegacy = false;

    public function boot(): void
    {
        Gate::authorize('viewAny', Company::class);
    }

    public function mount(int $companyId, ?int $computerId = null): void
    {
        $this->companyId = Company::findOrFail($companyId)->id;
        $this->computerId = $computerId === null ? null : Computer::where('company_id', $companyId)->findOrFail($computerId)->id;
    }

    #[Computed]
    public function computers(): Collection
    {
        return Computer::where('company_id', $this->companyId)->withCommercialPeriods()->orderBy('id')->get();
    }

    public function table(Table $table): Table
    {
        return $table->query(CompanyRenewal::where('company_id', $this->companyId)->with(['periods.computer', 'periods.originComputer']))
            ->defaultSort('id', 'desc')->stackedOnMobile()->recordAction('view')
            ->columns([
                TextColumn::make('reference')->label('Operazione')->weight('semibold')
                    ->description(fn (CompanyRenewal $record): string => $record->is_regrouped ? 'PC spostati · storico conservato' : ($record->detection_month ? 'Prima vendita · '.substr($record->detection_month, 5).'/'.substr($record->detection_month, 0, 4) : CompanyRenewal::KINDS[$record->kind])),
                TextColumn::make('occurred_on')->label('Data operazione')->date()->placeholder('Da verificare')->sortable(),
                TextColumn::make('quantity')->label('Licenze')->numeric()
                    ->description(fn (CompanyRenewal $record): ?string => $record->kind === 'upgrade' ? 'Intera azienda' : $record->periods->count().' PC collegati'),
                TextColumn::make('price_cents')->label('Totale operazione')->placeholder('Non indicato')
                    ->formatStateUsing(fn (int $state): string => '€ '.str_replace('.', ',', Euro::fromCents($state))),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Tipo')->options(CompanyRenewal::KINDS),
            ])
            ->headerActions([
                $this->createOperationAction('create', 'Vendita o rinnovo', 'initial'),
                $this->createOperationAction('upgrade', 'Upgrade aziendale', 'upgrade')->color('gray'),
            ])
            ->recordActions([
                $this->moveComputersAction(),
                Action::make('view')->label('Dettagli')->icon(Heroicon::OutlinedEye)->iconButton()->tooltip('Consulta operazione')
                    ->modalHeading(fn (CompanyRenewal $record): string => $record->reference.' · '.CompanyRenewal::KINDS[$record->kind])
                    ->modalWidth('5xl')->modalSubmitAction(false)->modalCancelActionLabel('Chiudi')
                    ->schema([\Filament\Schemas\Components\View::make('filament.commercial-operation')->viewData(fn (CompanyRenewal $record): array => ['operation' => $record])]),
                Action::make('link')->label('Collega computer')->icon(Heroicon::OutlinedLink)
                    ->visible(fn (CompanyRenewal $record): bool => $record->occurred_on === null)
                    ->authorize(fn (): bool => Gate::allows('update', Company::findOrFail($this->companyId)))
                    ->modalHeading('Collega la vendita ai computer')->modalWidth('5xl')
                    ->modalDescription('Conserva la registrazione esistente. Indica la data dell’operazione e verifica esplicitamente i computer e i loro periodi.')
                    ->modalSubmitActionLabel('Conferma collegamenti')->schema($this->operationFields())
                    ->fillForm(function (CompanyRenewal $record): array {
                        $data = $this->operationData($record);
                        $this->linkingLegacy = true;
                        $data['common_start'] = $record->starts_on->toDateString();
                        $data['common_end'] = $record->expires_on->toDateString();

                        return $data;
                    })
                    ->action(fn (array $data, Schema $schema, Action $action, CompanyRenewal $record) => $this->saveOperation($data, $schema, $action, $record)),
                Action::make('edit')->label('Correggi')->icon(Heroicon::OutlinedPencilSquare)->iconButton()->tooltip('Correggi operazione')
                    ->authorize(fn (): bool => Gate::allows('update', Company::findOrFail($this->companyId)))
                    ->modalHeading(fn (CompanyRenewal $record): string => 'Correggi '.$record->reference)
                    ->modalDescription('La correzione conserva l’operazione. Per acquistare un nuovo periodo registra un rinnovo.')
                    ->modalWidth('5xl')->modalSubmitActionLabel('Salva correzione')->schema($this->operationFields())
                    ->fillForm(fn (CompanyRenewal $record): array => $this->operationData($record))
                    ->action(fn (array $data, Schema $schema, Action $action, CompanyRenewal $record) => $this->saveOperation($data, $schema, $action, $record)),
            ])
            ->emptyStateHeading('Collega le licenze alla loro vendita')
            ->emptyStateDescription('Registra una vendita o un rinnovo e scegli i computer. Periodi e storico saranno consultabili anche dal singolo PC.');
    }

    private function createOperationAction(string $name, string $label, string $kind): Action
    {
        return Action::make($name)->label($label)->icon($kind === 'upgrade' ? Heroicon::OutlinedArrowUpCircle : Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => Gate::allows('update', Company::findOrFail($this->companyId)))
            ->modalHeading($kind === 'upgrade' ? 'Aggiorna la licenza aziendale' : 'Registra vendita o rinnovo')
            ->modalWidth('5xl')->modalSubmitActionLabel('Registra operazione')->schema($this->operationFields())
            ->fillForm(function () use ($kind): array {
                $this->linkingLegacy = false;
                $this->captureVersions();
                $pc = $this->computerId === null ? null : $this->computers->find($this->computerId);
                $renewal = $pc?->periods->isNotEmpty() ?? false;

                return [
                    'kind' => $kind === 'upgrade' ? 'upgrade' : ($renewal ? 'renewal' : 'initial'),
                    'occurred_on' => today('Europe/Rome')->toDateString(),
                    'quantity' => 1, 'computer_ids' => $pc === null ? [] : [$pc->id],
                    'periods' => $pc === null ? [] : [$this->periodData($pc, $renewal ? 'renewal' : 'initial')],
                ];
            })
            ->action(fn (array $data, Schema $schema, Action $action) => $this->saveOperation($data, $schema, $action));
    }

    private function moveComputersAction(): Action
    {
        return Action::make('moveComputers')->label('Sposta PC')->icon(Heroicon::OutlinedArrowsRightLeft)->iconButton()->tooltip('Sposta in un’altra vendita')
            ->visible(fn (CompanyRenewal $record): bool => $record->kind === 'initial' && $record->periods->isNotEmpty())
            ->authorize(fn (): bool => Gate::allows('update', Company::findOrFail($this->companyId)))
            ->modalHeading('Sposta i computer in un’altra vendita')->modalWidth('lg')->modalSubmitActionLabel('Conferma spostamento')
            ->modalDescription('Per esempio, riunisci i PC rilevati il mese successivo alla vendita originale. Le date individuali e i rinnovi restano invariati. Importi e riferimenti delle due operazioni non vengono sommati o spostati.')
            ->fillForm(function (): array {
                $this->captureVersions();
                $this->expectedVersions = CompanyRenewal::where('company_id', $this->companyId)->pluck('version', 'id')->all();

                return [];
            })
            ->schema([
                Select::make('computer_ids')->label('Computer da spostare')->multiple()->searchable()->required()
                    ->options(fn (CompanyRenewal $record): array => $record->periods->mapWithKeys(fn ($period): array => [$period->computer_id => $period->computer->display_name.' · '.$period->starts_on->format('d/m/Y')])->all()),
                Select::make('target_id')->label('Vendita di destinazione')->searchable()->required()
                    ->options(fn (CompanyRenewal $record): array => CompanyRenewal::where('company_id', $this->companyId)->where('kind', 'initial')->whereNotNull('occurred_on')->whereKeyNot($record->id)
                        ->get()->mapWithKeys(fn (CompanyRenewal $sale): array => [$sale->id => $sale->reference.' · '.($sale->occurred_on ?? $sale->starts_on)->format('d/m/Y').' · '.$sale->quantity.' licenze'])->all()),
            ])
            ->action(function (array $data, CompanyRenewal $record, Schema $schema, Action $action): void {
                try {
                    Company::findOrFail($this->companyId)->moveInitialPeriods($record->id, (int) $data['target_id'], $data['computer_ids'], $this->expectedVersions, $this->expectedComputerVersions);
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Spostamento non eseguito')->body(collect($exception->errors())->flatten()->first())->send();
                    throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key): array => [$schema->getStatePath().'.'.$key => $messages])->all());
                } catch (QueryException|ModelNotFoundException) {
                    Notification::make()->danger()->title('Spostamento non riuscito')->body('Riapri il registro e riprova. Nessuna modifica è stata confermata.')->send();
                    $action->halt();
                }
                unset($this->computers);
                $this->flushCachedTableRecords();
                $this->dispatch('company-renewals-updated');
                Notification::make()->success()->title('Computer spostati nella vendita')->send();
            });
    }

    private function operationFields(): array
    {
        return [
            Section::make('Operazione')->columns(2)->schema([
                Select::make('kind')->label('Tipo')->options(CompanyRenewal::KINDS)->required()->live()
                    ->disabled(fn (?CompanyRenewal $record): bool => $record !== null)->dehydrated(),
                DatePicker::make('occurred_on')->label('Data operazione')->native(false)->displayFormat('d/m/Y')
                    ->required(fn (?CompanyRenewal $record): bool => $record === null || $record->occurred_on !== null || $this->linkingLegacy),
                TextInput::make('quantity')->label('Quantità venduta')->numeric()->integer()->minValue(fn (?CompanyRenewal $record): int => $record?->is_regrouped ? 0 : 1)->maxValue(4294967295)
                    ->required()->visible(fn (Get $get): bool => $get('kind') !== 'upgrade')
                    ->helperText('Può includere licenze non ancora collegate a un computer.'),
            ]),
            Section::make('Computer e periodi')->description('Ogni computer conserva il proprio periodo. Puoi uniformare le date o mantenerle differenti.')
                ->visible(fn (Get $get, ?CompanyRenewal $record): bool => ! $record?->is_regrouped && $get('kind') !== 'upgrade' && ($record === null || $record->occurred_on !== null || $this->linkingLegacy))
                ->schema([
                    Select::make('computer_ids')->label('Computer coinvolti')->multiple()->searchable()->live()->dehydrated(false)
                        ->helperText('Nelle correzioni conserva i computer già presenti; puoi aggiungerne altri entro la quantità venduta.')
                        ->options(fn (): array => $this->computers->mapWithKeys(fn (Computer $pc): array => [$pc->id => $pc->display_name.' · '.($pc->expires_on?->format('d/m/Y') ?? 'senza scadenza')])->all())
                        ->afterStateUpdated(function (?array $state, Get $get, Set $set): void {
                            $existing = collect($get('periods') ?? [])->keyBy('computer_id');
                            $rows = [];
                            foreach ($state ?? [] as $id) {
                                $pc = $this->computers->find($id);
                                if ($pc !== null) {
                                    $rows[] = $existing->get($id) ?? $this->periodData($pc, $get('kind'));
                                }
                            }
                            $set('periods', $rows);
                            $set('quantity', max(count($rows), (int) $get('quantity'), 1));
                        }),
                    Section::make('Date comuni')->compact()->columns(2)->schema([
                        DatePicker::make('common_start')->label('Inizio per tutti')->native(false)->displayFormat('d/m/Y')->dehydrated(false),
                        DatePicker::make('common_end')->label('Scadenza per tutti')->native(false)->displayFormat('d/m/Y')->dehydrated(false),
                        Actions::make([
                            Action::make('applyDates')->label('Applica ai computer selezionati')->color('gray')
                                ->action(function (Get $get, Set $set): void {
                                    $dates = validator(['start' => $get('common_start'), 'end' => $get('common_end')], [
                                        'start' => ['required', 'date_format:Y-m-d'], 'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
                                    ])->validate();
                                    $set('periods', array_map(fn (array $row): array => array_replace($row, ['starts_on' => $dates['start'], 'expires_on' => $dates['end']]), $get('periods') ?? []));
                                }),
                        ])->columnSpanFull(),
                    ]),
                    Repeater::make('periods')->label('Validità per computer')->addable(false)->deletable(false)->reorderable(false)->defaultItems(0)->minItems(1)->required()
                        ->extraAttributes(['class' => 'bl-periods-table', 'tabindex' => '0'])
                        ->table([
                            TableColumn::make('Computer')->width('25%'),
                            TableColumn::make('Licenza assegnata')->width('31%'),
                            TableColumn::make('Inizio periodo')->width('22%')->markAsRequired(),
                            TableColumn::make('Scadenza inclusa')->width('22%'),
                        ])
                        ->schema([
                            Hidden::make('computer_id')->required(),
                            TextEntry::make('computer_name')->label('Computer')->weight('semibold')
                                ->state(fn (Get $get): string => $this->computers->find($get('computer_id'))?->display_name ?? 'Computer'),
                            TextEntry::make('assigned_license')->label('Licenza assegnata')
                                ->state(fn (Get $get): string => $this->computers->find($get('computer_id'))?->company_product ?? 'Non disponibile'),
                            DatePicker::make('starts_on')->label('Inizio periodo')->native(false)->displayFormat('d/m/Y')->required(),
                            DatePicker::make('expires_on')->label('Scadenza inclusa')->native(false)->displayFormat('d/m/Y')->nullable()
                                ->required(fn (?CompanyRenewal $record): bool => $record === null || ($record->detection_month === null && ! $record->periods->contains('expires_on', null)))->afterOrEqual('starts_on'),
                        ]),
                ]),
            Section::make('Registro precedente')->description('Questo periodo aziendale non identifica i computer coinvolti. Non vengono ricostruiti collegamenti automaticamente.')
                ->visible(fn (?CompanyRenewal $record): bool => $record !== null && $record->occurred_on === null && ! $this->linkingLegacy)->columns(2)->schema([
                    DatePicker::make('starts_on')->label('Inizio')->native(false)->displayFormat('d/m/Y')->required(),
                    DatePicker::make('expires_on')->label('Scadenza')->native(false)->displayFormat('d/m/Y')->required()->afterOrEqual('starts_on'),
                ]),
            Section::make('Upgrade per l’intera azienda')->visible(fn (Get $get): bool => $get('kind') === 'upgrade')
                ->description('La licenza è quella già assegnata ai PC. La data dell’operazione indica la decorrenza dell’aggiornamento. Le scadenze restano invariate e l’upgrade non conta come rinnovo. Inserisci qui sotto la differenza complessiva per i periodi residui.')
                ->schema([
                    TextEntry::make('assigned_licenses')->label('Licenze assegnate')->listWithLineBreaks()
                        ->state(fn (): array => $this->computers->map(fn (Computer $pc): string => $pc->display_name.' · '.($pc->company_product ?? 'Non disponibile'))->all()),
                ]),
            Section::make('Importo e riferimenti')->columns(2)->schema([
                TextInput::make('amount')->label(fn (Get $get): string => $get('kind') === 'upgrade' ? 'Differenza complessiva' : 'Totale operazione')
                    ->prefix('€')->inputMode('decimal')->nullable()->rules(['string', 'regex:'.Euro::PATTERN])->maxLength(12)
                    ->required(fn (Get $get): bool => $get('kind') === 'upgrade')
                    ->helperText('Importo inserito manualmente, senza ripartizioni o calcoli automatici.'),
                TextInput::make('invoice_reference')->label('Riferimento fattura')->maxLength(255),
                TextInput::make('quote_reference')->label('Riferimento preventivo')->maxLength(255),
            ])->visible(fn (?CompanyRenewal $record): bool => $record === null || $record->occurred_on !== null || $this->linkingLegacy),
        ];
    }

    private function periodData(Computer $pc, string $kind): array
    {
        $last = $pc->periods->sortByDesc('expires_on')->first();
        $start = $kind === 'renewal' && $last !== null ? $last->expires_on?->copy()->addDay()->toDateString() : $pc->activated_on?->toDateString();

        return ['computer_id' => $pc->id, 'starts_on' => $start, 'expires_on' => $kind === 'renewal' ? null : $pc->expires_on?->toDateString()];
    }

    private function captureVersions(): void
    {
        unset($this->computers);
        $this->expectedComputerVersions = $this->computers->pluck('commercial_version', 'id')->all();
    }

    private function operationData(CompanyRenewal $record): array
    {
        $this->linkingLegacy = false;
        $this->captureVersions();
        $this->expectedVersions = [$record->id => $record->version];

        return $record->only(['kind', 'quantity', 'invoice_reference', 'quote_reference']) + [
            'occurred_on' => $record->occurred_on?->toDateString(), 'starts_on' => $record->starts_on?->toDateString(),
            'expires_on' => $record->expires_on?->toDateString(),
            'amount' => $record->price_cents === null ? null : Euro::fromCents($record->price_cents),
            'computer_ids' => $record->periods->pluck('computer_id')->all(),
            'periods' => $record->periods->map(fn ($period): array => ['computer_id' => $period->computer_id, 'starts_on' => $period->starts_on->toDateString(), 'expires_on' => $period->expires_on?->toDateString()])->all(),
        ];
    }

    private function saveOperation(array $data, Schema $schema, Action $action, ?CompanyRenewal $record = null): void
    {
        $company = Company::findOrFail($this->companyId);
        Gate::authorize('update', $company);
        try {
            if ($record !== null && $record->occurred_on === null && ! $this->linkingLegacy) {
                $company->saveLicensePeriod(array_intersect_key($data, array_flip(['kind', 'starts_on', 'expires_on', 'quantity'])), $record->id, $this->expectedVersions[$record->id] ?? -1);
            } else {
                $company->saveCommercialOperation($data, $record?->id, $record === null ? null : ($this->expectedVersions[$record->id] ?? -1), $this->expectedComputerVersions);
            }
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Verifica l’operazione')->body(collect($exception->errors())->flatten()->first())->send();
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[$schema->getStatePath().'.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } catch (QueryException|ModelNotFoundException) {
            Notification::make()->danger()->title('Salvataggio non riuscito')->body('Riapri l’operazione e riprova. Nessuna modifica è stata confermata.')->send();
            $action->halt();
        }
        unset($this->computers);
        $this->flushCachedTableRecords();
        $this->dispatch('company-renewals-updated');
        Notification::make()->success()->title('Operazione registrata')->send();
    }

    public function render(): View
    {
        return view('livewire.company-renewals');
    }
}
