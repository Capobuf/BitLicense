<?php

namespace App\Filament\Pages;

use App\Integrations\GravityZone\CompanySynchronizer;
use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneException;
use App\Livewire\CompanyLicenses;
use App\Models\Company;
use App\Models\Computer;
use App\Models\GravityZoneSetting;
use App\Support\Euro;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

class Licenze extends Dashboard implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Licenze';

    protected ?string $subheading = 'Scegli l’azienda e clicca un computer per aggiornare le sue date commerciali.';

    public ?array $data = [];

    protected bool $showCompanyName = false;

    #[Locked]
    public array $expectedVersions = [];

    #[Locked]
    public ?int $creatingCompanyId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessPanel(Filament::getPanel('admin')) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->form->fill();
        $companyId = filter_var(request()->query('company'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($companyId && Company::whereKey($companyId)->exists()) {
            $this->data['company_id'] = $companyId;
        } elseif (request()->query('company') === 'all') {
            $this->data['company_id'] = 'all';
        }
        $status = request()->query('status');
        if (in_array($status, ['missing', 'expired', 'expiring', 'active', 'future'], true)) {
            $this->tableFilters['status'] = ['value' => $status];
        }
        $dates = request()->only(['expires_from', 'expires_until']);
        if (validator($dates, [
            'expires_from' => ['required', 'date_format:Y-m-d'],
            'expires_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:expires_from'],
        ])->passes()) {
            $this->tableFilters['expiry'] = ['from' => $dates['expires_from'], 'until' => $dates['expires_until']];
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('form'),
            EmbeddedTable::make()->hidden(fn (): bool => ($this->data['company_id'] ?? null) === 'all'),
            View::make('filament.all-companies')
                ->visible(fn (): bool => ($this->data['company_id'] ?? null) === 'all')
                ->viewData(fn (): array => ['companies' => Company::query()->orderBy('name')->get(['id'])]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('company_id')->label('Azienda')->placeholder('Cerca e scegli un’azienda')->searchable()->live()->allowHtml()->wrapOptionLabels()
                ->options(fn (): array => ['all' => view('filament.company-option', ['company' => null])->render()] + Company::query()
                    ->orderBy('name')->get(['id', 'name', 'is_suspended', 'is_present', 'sync_error', 'technical_expires_on'])
                    ->mapWithKeys(fn (Company $company): array => [$company->id => view('filament.company-option', ['company' => $company])->render()])->all())
                ->getSearchResultsUsing(fn (Select $component, string $search): array => array_filter($component->getOptions(),
                    fn (string $label): bool => Str::contains(Str::ascii(html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8')), Str::ascii($search), ignoreCase: true)))
                ->afterStateUpdated(function (): void {
                    unset($this->referenceColumns);
                    $this->resetTable();
                    $this->resetTableSearch();
                    $this->removeTableFilters();
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->query(fn (): Builder => Computer::query()->where('company_id', $this->selectedCompanyId())->with(['company', 'gravityzoneCompany']))
            ->header(function () {
                $company = Company::find($this->selectedCompanyId());
                $counts = $company === null ? collect() : Computer::query()->where('company_id', $company->id)
                    ->get(['activated_on', 'expires_on'])->countBy(fn (Computer $computer): string => $computer->commercialStatus());

                return view('filament.licenses-company', [
                    'company' => $company,
                    'showCompanyName' => $this->showCompanyName,
                    'metrics' => [
                        'Computer' => $counts->sum(),
                        'Da completare' => $counts->get('Da completare', 0),
                        'Scadute' => $counts->get('Scaduta', 0),
                        'In scadenza' => $counts->get('In scadenza', 0),
                    ],
                    'settings' => GravityZoneSetting::query()->select(['id', 'companies_sync_error', 'endpoints_sync_error'])->find(1),
                ]);
            })
            ->extraAttributes(['class' => 'bl-computer-table'])
            ->stackedOnMobile()->recordAction('edit')->defaultSort('id', 'desc')
            ->reorderableColumns()
            ->filtersTriggerAction(fn (Action $action): Action => $action->label('Filtri')->iconButton()->tooltip('Filtri'))
            ->columnManagerTriggerAction(fn (Action $action): Action => $action->label('Colonne')->iconButton()->tooltip('Colonne'))
            ->columns([
                TextColumn::make('display_name')->label('Computer')->weight('medium')->wrap()->toggleable()->searchable(['technical_name', 'name'])
                    ->description(fn (Computer $record): string => $record->commercialStatus().' · '.($record->gravityzone_id === null ? 'Manuale' : ($record->gravityzone_company_id !== $record->company_id
                        ? 'Da verificare: rilevato in '.$record->gravityzoneCompany?->name
                        : ($record->is_present ? 'GravityZone' : 'Non più rilevato · dati conservati')))),
                ViewColumn::make('validity')->label('Validità')->view('filament.computer-validity')
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy('expires_on', $direction))->toggleable(),
                TextColumn::make('price_cents')->label('Prezzo')->sortable()->placeholder('Non indicato')
                    ->formatStateUsing(fn (int $state): string => '€ '.str_replace('.', ',', Euro::fromCents($state))),
                TextColumn::make('quote_reference')->label('Rif. Preventivo')->wrap()->placeholder('—')
                    ->visible(fn (): bool => $this->referenceColumns['quote_reference']),
                TextColumn::make('invoice_reference')->label('Rif. Fattura')->wrap()->placeholder('—')
                    ->visible(fn (): bool => $this->referenceColumns['invoice_reference']),
                TextColumn::make('company_product')->label('Prodotto aziendale')->placeholder('Non fornito dall’API')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')->label('Note')->wrap()->limit(80)->tooltip(fn (Computer $record): ?string => $record->notes)
                    ->placeholder('Nessuna nota')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('platform')->label('Sistema Operativo')->wrap()->placeholder('Non disponibile')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gravityzoneCompany.name')->label('Azienda Rilevata')->wrap()->placeholder('Non disponibile')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_at')->label('Ultimo Rilevamento')->dateTime()->placeholder('Non disponibile')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato commerciale')->options([
                    'missing' => 'Da completare', 'expired' => 'Scaduta', 'expiring' => 'In scadenza entro 30 giorni',
                    'active' => 'Attiva', 'future' => 'Da attivare',
                ])->query(function (Builder $query, array $data): Builder {
                    $day = today('Europe/Rome')->toDateString();
                    $horizon = today('Europe/Rome')->addDays(30)->toDateString();

                    return match ($data['value'] ?? null) {
                        'missing' => $query->where(fn (Builder $q): Builder => $q->whereNull('activated_on')->orWhereNull('expires_on')),
                        'expired' => $query->whereNotNull('activated_on')->whereDate('expires_on', '<', $day),
                        'expiring' => $query->whereDate('activated_on', '<=', $day)->whereDate('expires_on', '>=', $day)->whereDate('expires_on', '<=', $horizon),
                        'active' => $query->whereDate('activated_on', '<=', $day)->whereDate('expires_on', '>', $horizon),
                        'future' => $query->whereNotNull('expires_on')->whereDate('activated_on', '>', $day),
                        default => $query,
                    };
                }),
                Filter::make('expiry')->label('Scadenza commerciale')->schema([
                    TextInput::make('from')->label('Scadenza dal')->type('date')->rules(['nullable', 'date_format:Y-m-d']),
                    TextInput::make('until')->label('Scadenza al')->type('date')->rules(['nullable', 'date_format:Y-m-d'])
                        ->afterOrEqual(fn (Get $get): ?string => filled($get('from')) ? 'from' : null),
                ])->query(function (Builder $query, array $data): Builder {
                    if (validator($data, [
                        'from' => ['nullable', 'date_format:Y-m-d'],
                        'until' => ['nullable', 'date_format:Y-m-d', ...(! empty($data['from']) ? ['after_or_equal:from'] : [])],
                    ])->fails()) {
                        return $query->whereRaw('1 = 0');
                    }

                    return $query->when(filled($data['from'] ?? null), fn (Builder $query): Builder => $query->whereDate('expires_on', '>=', $data['from']))
                        ->when(filled($data['until'] ?? null), fn (Builder $query): Builder => $query->whereDate('expires_on', '<=', $data['until']));
                })->indicateUsing(function (array $data): array {
                    $indicators = [];
                    foreach (['from' => 'Scadenza dal ', 'until' => 'Scadenza al '] as $key => $label) {
                        if (filled($data[$key] ?? null) && validator([$key => $data[$key]], [$key => 'date_format:Y-m-d'])->passes()) {
                            $indicators[] = $label.CarbonImmutable::parse($data[$key])->format('d/m/Y');
                        }
                    }

                    return $indicators;
                }),
            ])
            ->recordActions([
                $this->editAction(),
                ActionGroup::make([$this->transferAction(), $this->reconcileAction()])->label('Altre azioni'),
            ])
            ->emptyStateHeading(fn (): string => empty($this->data['company_id']) ? 'Scegli un’azienda per vedere i computer' : 'Nessun computer da mostrare')
            ->emptyStateDescription('I computer importati compaiono automaticamente. Puoi anche aggiungere un PC manuale.');
    }

    #[Computed]
    public function referenceColumns(): array
    {
        $references = Computer::query()->where('company_id', $this->selectedCompanyId())->toBase()
            ->selectRaw("MAX(CASE WHEN TRIM(quote_reference) <> '' THEN 1 ELSE 0 END) AS has_quote, MAX(CASE WHEN TRIM(invoice_reference) <> '' THEN 1 ELSE 0 END) AS has_invoice")
            ->first();

        return ['quote_reference' => (bool) $references->has_quote, 'invoice_reference' => (bool) $references->has_invoice];
    }

    private function computerSections(): array
    {
        return [
            Section::make('Computer')->icon(Heroicon::OutlinedComputerDesktop)->columns(2)->schema([
                TextInput::make('name')->label('Nome Computer')->required()->maxLength(255)
                    ->visible(fn (?Computer $record): bool => $record?->gravityzone_id === null),
                TextEntry::make('technical_name')->label('Nome Computer')->hiddenLabel()->size(TextSize::Large)->weight('semibold')
                    ->helperText(fn (?Computer $record): string => $record?->platform ?? 'Sistema operativo non disponibile')
                    ->visible(fn (?Computer $record): bool => $record?->gravityzone_id !== null),
                TextEntry::make('company_product')->label('Licenza')
                    ->state(fn (?Computer $record): ?string => $record === null
                        ? Company::find($this->creatingCompanyId)?->technical_product : $record->company_product)
                    ->placeholder('Non fornito dall’API')->wrap(),
                TextEntry::make('product')->label('Prodotto Commerciale Salvato')->wrap()->columnSpanFull()
                    ->visible(fn (?Computer $record): bool => filled($record?->product)),
            ]),
            Section::make('Licenza Commerciale')->icon(Heroicon::OutlinedKey)->columns(2)->schema([
                DatePicker::make('activated_on')->label('Attivazione')->placeholder('es. 01/01/2026')->native(false)->displayFormat('d/m/Y')->nullable()->live()
                    ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                        if (filled($state) && blank($get('expires_on')) && validator(['date' => $state], ['date' => 'date_format:Y-m-d'])->passes()) {
                            $set('expires_on', CarbonImmutable::parse($state)->addYearNoOverflow()->toDateString());
                        }
                    }),
                DatePicker::make('expires_on')->label('Scadenza')->placeholder('es. 31/12/2026')->native(false)->displayFormat('d/m/Y')->nullable(),
                TextInput::make('amount')->label('Prezzo')->prefix('€')->inputMode('decimal')->nullable()
                    ->rules(['string', 'regex:'.Euro::PATTERN])->maxLength(12)
                    ->validationMessages(['regex' => 'Usa un importo non negativo con al massimo due decimali, senza separatori delle migliaia.']),
            ]),
            Section::make('Riferimenti e Note')->icon(Heroicon::OutlinedDocumentText)->columns(2)->schema([
                TextInput::make('quote_reference')->label('Riferimento Preventivo')->maxLength(255),
                TextInput::make('invoice_reference')->label('Riferimento Fattura')->maxLength(255),
                Textarea::make('notes')->label('Note')->maxLength(10000)->rows(2)->columnSpanFull(),
            ]),
            Section::make('Storico Automatico')->collapsed()->visible(fn (?Computer $record): bool => filled($record?->history))
                ->schema([View::make('filament.computer-history')->viewData(fn (Computer $record): array => ['history' => $record->history])]),
        ];
    }

    private function editAction(): Action
    {
        return Action::make('edit')->label('Modifica')->icon(Heroicon::OutlinedPencilSquare)->iconButton()->tooltip('Modifica')->authorize('update')
            ->modalHeading(fn (Computer $record) => new HtmlString(view('filament.computer-heading', ['computer' => $record])->render()))
            ->modalSubmitActionLabel('Salva')->schema($this->computerSections())
            ->fillForm(function (Computer $record): array {
                $this->expectedVersions = [$record->id => $record->commercial_version];

                return $record->only(['name', 'invoice_reference', 'quote_reference', 'notes']) + [
                    'activated_on' => $record->activated_on?->toDateString(), 'expires_on' => $record->expires_on?->toDateString(),
                    'amount' => $record->price_cents === null ? null : Euro::fromCents($record->price_cents),
                ];
            })->action(function (Computer $record, array $data, Schema $schema, Action $action): void {
                Gate::authorize('update', $record);
                $this->save($schema, $action, fn () => $record->updateCommercial($data, $this->expectedVersions[$record->id] ?? -1));
            });
    }

    private function transferAction(): Action
    {
        return Action::make('transfer')->label('Trasferisci licenza al sostitutivo')->authorize('update')
            ->visible(fn (Computer $record): bool => $record->activated_on !== null && $record->expires_on !== null)
            ->modalHeading('Trasferisci la licenza pagata')->modalSubmitActionLabel('Conferma trasferimento')
            ->modalDescription('I dati commerciali passano al PC scelto; il precedente conserva lo storico, senza duplicare il periodo pagato. Il prodotto aziendale segue GravityZone, che non viene modificato.')
            ->fillForm(function (Computer $record): array {
                $this->expectedVersions = [$record->id => $record->commercial_version];

                return [];
            })->schema([
                TextEntry::make('expires_on')->label('Scadenza conservata')->date(),
                Select::make('target_id')->label('Computer sostitutivo')->required()->searchable()
                    ->noOptionsMessage('Nessun computer disponibile senza dati commerciali.')
                    ->options(fn (Computer $record): array => Computer::query()->where('company_id', $record->company_id)->whereKeyNot($record->id)
                        ->whereNull('activated_on')->whereNull('expires_on')->orderBy('id')->get()
                        ->reject(fn (Computer $pc): bool => $pc->hasCommercialData())
                        ->mapWithKeys(fn (Computer $pc): array => [$pc->id => '#'.$pc->id.' · '.$pc->display_name])->all()),
            ])->action(function (Computer $record, array $data, Schema $schema, Action $action): void {
                Gate::authorize('update', $record);
                $this->save($schema, $action, fn () => $record->transferTo(Computer::findOrFail($data['target_id']), $this->expectedVersions[$record->id] ?? -1));
            });
    }

    private function reconcileAction(): Action
    {
        return Action::make('reconcile')->label('Collega a PC GravityZone rilevato')->authorize('update')
            ->visible(fn (Computer $record): bool => $record->gravityzone_id === null)
            ->modalHeading('Conferma che si tratta dello stesso computer')->modalSubmitActionLabel('Conferma collegamento')
            ->modalDescription('Verifica l’ID GravityZone: il nome da solo non basta. Le date e le note manuali restano; la riga importata vuota viene riunita a questa.')
            ->fillForm(function (Computer $record): array {
                $this->expectedVersions = [$record->id => $record->commercial_version];

                return [];
            })->schema([
                Select::make('target_id')->label('Computer rilevato')->required()->searchable()
                    ->noOptionsMessage('Nessun computer GravityZone disponibile senza dati locali o storico.')
                    ->options(fn (Computer $record): array => Computer::query()->where('company_id', $record->company_id)
                        ->where('gravityzone_company_id', $record->company_id)->whereNotNull('gravityzone_id')->where('is_present', true)
                        ->whereHas('gravityzoneCompany', fn (Builder $query): Builder => $query->where('is_present', true))
                        ->whereNull('activated_on')->whereNull('expires_on')->orderBy('id')->get()
                        ->reject(fn (Computer $pc): bool => $pc->hasCommercialData() || filled($pc->notes) || filled($pc->name) || ! empty($pc->history))
                        ->mapWithKeys(fn (Computer $pc): array => [$pc->id => $pc->display_name.' · ID '.$pc->gravityzone_id.' · '.($pc->platform ?? 'Sistema non noto')])->all()),
            ])->action(function (Computer $record, array $data, Schema $schema, Action $action): void {
                Gate::authorize('update', $record);
                $this->save($schema, $action, fn () => $record->reconcileWith(Computer::findOrFail($data['target_id']), $this->expectedVersions[$record->id] ?? -1));
            });
    }

    private function save(Schema $schema, Action $action, Closure $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $key => $messages) {
                $errors[$schema->getStatePath().'.'.$key] = $messages;
            }
            throw ValidationException::withMessages($errors);
        } catch (QueryException|ModelNotFoundException) {
            Notification::make()->danger()->title('Salvataggio non riuscito')->body('Riapri la scheda e riprova. Nessuna modifica è stata confermata.')->send();
            $action->halt();
        }
        $this->flushCachedTableRecords();
        unset($this->referenceColumns);
        $this->getTable()->flushCachedVisibleColumns();
        Notification::make()->success()->title('Dati salvati')->send();
    }

    protected function selectedCompanyId(): ?int
    {
        return filter_var($this->data['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }

    protected function createAction(): Action
    {
        return Action::make('create')->label('Aggiungi PC manuale')->authorize('create', Computer::class)
            ->disabled(fn (): bool => ! Company::whereKey($this->selectedCompanyId())->exists())
            ->modalHeading('Aggiungi un computer')->modalSubmitActionLabel('Salva')
            ->fillForm(function (): array {
                $this->creatingCompanyId = Company::findOrFail($this->selectedCompanyId())->id;

                return [];
            })->schema($this->computerSections())
            ->action(function (array $data, Schema $schema, Action $action): void {
                Gate::authorize('create', Computer::class);
                $this->save($schema, $action, fn () => DB::transaction(function () use ($data): void {
                    $company = Company::findOrFail($this->creatingCompanyId);
                    $computer = Computer::create(['company_id' => $company->id, 'name' => $data['name']]);
                    $computer->updateCommercial($data, 0);
                }));
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->createAction()->visible(fn (): bool => ($this->data['company_id'] ?? null) !== 'all'),
            Action::make('synchronize')->label('Aggiorna da GravityZone')->color('gray')->authorize('synchronize', Company::class)
                ->requiresConfirmation()->modalHeading('Aggiornare aziende e computer?')
                ->modalDescription('Legge GravityZone e conserva date, prezzi e note locali. I nuovi computer compaiono con le date da completare.')
                ->action(function (): void {
                    Gate::authorize('synchronize', Company::class);
                    try {
                        app(CompanySynchronizer::class)->sync();
                        app(EndpointSynchronizer::class)->sync();
                        Notification::make()->success()->title('Sincronizzazione completata')->send();
                    } catch (GravityZoneException $exception) {
                        Notification::make()->danger()->title('Sincronizzazione non completata')->body($exception->getMessage())->send();
                    }
                    $this->flushCachedTableRecords();
                    $this->dispatch('refresh-page')->to(CompanyLicenses::class);
                }),
        ];
    }
}
