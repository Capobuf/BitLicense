<?php

namespace App\Filament\Pages;

use App\Integrations\GravityZone\CompanySynchronizer;
use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneException;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class Licenze extends Dashboard implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Licenze';

    protected ?string $subheading = 'Scegli l’azienda e clicca un computer per aggiornare le sue date commerciali.';

    public ?array $data = [];

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
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedSchema::make('form'), EmbeddedTable::make()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Select::make('company_id')->label('Azienda')->placeholder('Cerca e scegli un’azienda')->searchable()->live()
                ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                ->afterStateUpdated(function (): void {
                    $this->resetTable();
                    $this->resetTableSearch();
                    $this->removeTableFilters();
                }),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->query(fn (): Builder => Computer::query()->where('company_id', $this->data['company_id'] ?? null)->with('gravityzoneCompany'))
            ->header(fn () => view('filament.licenses-company', [
                'company' => Company::find($this->data['company_id'] ?? null),
                'settings' => GravityZoneSetting::query()->select(['id', 'companies_sync_error', 'endpoints_sync_error', 'endpoints_synced_at'])->find(1),
            ]))
            ->stackedOnMobile()->recordAction('edit')->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('display_name')->label('Computer')->weight('medium')->wrap()->searchable(['technical_name', 'name'])
                    ->description(fn (Computer $record): string => $record->gravityzone_id === null ? 'Manuale' : ($record->gravityzone_company_id !== $record->company_id
                        ? 'Da verificare: rilevato in '.$record->gravityzoneCompany?->name
                        : ($record->is_present ? 'GravityZone' : 'Non più rilevato · dati conservati'))),
                TextColumn::make('product')->label('Prodotto / licenza')->placeholder('Da indicare')->wrap(),
                TextColumn::make('activated_on')->label('Attivazione')->date()->placeholder('Da completare')->sortable(),
                TextColumn::make('expires_on')->label('Scadenza commerciale')->date()->placeholder('Da completare')->sortable(),
                TextColumn::make('status')->label('Stato')->badge()->state(fn (Computer $record): string => self::status($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Scaduta' => 'danger', 'Attiva' => 'success', 'Da attivare' => 'info', default => 'warning',
                    }),
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
            ])
            ->recordActions([
                $this->editAction(),
                ActionGroup::make([$this->transferAction(), $this->reconcileAction()])->label('Altre azioni'),
            ])
            ->emptyStateHeading(fn (): string => empty($this->data['company_id']) ? 'Scegli un’azienda per vedere i computer' : 'Nessun computer da mostrare')
            ->emptyStateDescription('I computer importati compaiono automaticamente. Puoi anche aggiungere un PC manuale.');
    }

    private static function status(Computer $computer): string
    {
        $day = today('Europe/Rome');

        return match (true) {
            $computer->activated_on === null || $computer->expires_on === null => 'Da completare',
            $computer->expires_on->lt($day) => 'Scaduta',
            $computer->activated_on->gt($day) => 'Da attivare',
            $computer->expires_on->lte($day->addDays(30)) => 'In scadenza',
            default => 'Attiva',
        };
    }

    private function commercialFields(): array
    {
        return [
            TextInput::make('name')->label('Nome computer')->required()->maxLength(255)
                ->visible(fn (?Computer $record): bool => $record?->gravityzone_id === null),
            TextEntry::make('technical_name')->label('Nome tecnico GravityZone · sola lettura')
                ->visible(fn (?Computer $record): bool => $record?->gravityzone_id !== null),
            TextInput::make('product')->label('Prodotto / licenza')->maxLength(255)
                ->helperText('Facoltativo. Indicalo manualmente se non disponibile.'),
            DatePicker::make('activated_on')->label('Data attivazione')->native(false)->displayFormat('d/m/Y')->nullable()->live()
                ->afterStateUpdated(function (mixed $state, Get $get, Set $set): void {
                    if (filled($state) && blank($get('expires_on')) && validator(['date' => $state], ['date' => 'date_format:Y-m-d'])->passes()) {
                        $set('expires_on', CarbonImmutable::parse($state)->addYearNoOverflow()->toDateString());
                    }
                }),
            DatePicker::make('expires_on')->label('Scadenza commerciale')->native(false)->displayFormat('d/m/Y')->nullable()
                ->helperText('Ultimo giorno incluso. Il suggerimento di un anno è modificabile.'),
            TextInput::make('amount')->label('Prezzo facoltativo')->prefix('€')->inputMode('decimal')->nullable()
                ->rules(['string', 'regex:'.Euro::PATTERN])->maxLength(12)
                ->validationMessages(['regex' => 'Usa un importo non negativo con al massimo due decimali, senza separatori delle migliaia.']),
            Textarea::make('commercial_description')->label('Descrizione commerciale')->maxLength(10000)->rows(2)->columnSpanFull(),
            TextInput::make('invoice_reference')->label('Riferimento fattura')->maxLength(255),
            TextInput::make('quote_reference')->label('Riferimento preventivo')->maxLength(255),
            Textarea::make('notes')->label('Note')->maxLength(10000)->rows(2)->columnSpanFull(),
        ];
    }

    private function editAction(): Action
    {
        return Action::make('edit')->label('Modifica')->authorize('update')->modalHeading(fn (Computer $record): string => $record->display_name)
            ->modalSubmitActionLabel('Salva')->schema([
                Section::make()->columns(2)->schema($this->commercialFields()),
                Section::make('Dettagli GravityZone')->collapsed()->visible(fn (Computer $record): bool => $record->gravityzone_id !== null)->columns(2)->schema([
                    TextEntry::make('gravityzone_id')->label('ID GravityZone')->wrap(),
                    TextEntry::make('gravityzoneCompany.name')->label('Azienda rilevata'),
                    TextEntry::make('platform')->label('Sistema operativo')->placeholder('Non disponibile'),
                    TextEntry::make('last_seen_at')->label('Ultimo rilevamento')->dateTime()->placeholder('Non disponibile'),
                ]),
                Section::make('Storico automatico')->collapsed()->visible(fn (Computer $record): bool => filled($record->history))
                    ->schema([View::make('filament.computer-history')->viewData(fn (Computer $record): array => ['history' => $record->history])]),
            ])->fillForm(function (Computer $record): array {
                $this->expectedVersions = [$record->id => $record->commercial_version];

                return $record->only(['name', 'product', 'commercial_description', 'invoice_reference', 'quote_reference', 'notes']) + [
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
            ->modalDescription('Date, prodotto, prezzo e riferimenti passano al PC scelto. Il PC precedente conserva lo storico, senza una seconda licenza attiva. Nessuna modifica a GravityZone.')
            ->fillForm(function (Computer $record): array {
                $this->expectedVersions = [$record->id => $record->commercial_version];

                return [];
            })->schema([
                TextEntry::make('expires_on')->label('Scadenza conservata')->date(),
                Select::make('target_id')->label('Computer sostitutivo')->required()->searchable()
                    ->options(fn (Computer $record): array => Computer::query()->where('company_id', $record->company_id)->whereKeyNot($record->id)
                        ->whereNull('activated_on')->whereNull('expires_on')->orderBy('id')->get()
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
                    ->options(fn (Computer $record): array => Computer::query()->where('company_id', $record->company_id)
                        ->where('gravityzone_company_id', $record->company_id)->whereNotNull('gravityzone_id')->where('is_present', true)
                        ->whereNull('activated_on')->whereNull('expires_on')->orderBy('id')->get()
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
        Notification::make()->success()->title('Dati salvati')->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('Aggiungi PC manuale')->authorize('create', Computer::class)
                ->disabled(fn (): bool => ! Company::whereKey($this->data['company_id'] ?? null)->exists())
                ->modalHeading('Aggiungi un computer')->modalSubmitActionLabel('Salva')
                ->fillForm(function (): array {
                    $this->creatingCompanyId = Company::findOrFail($this->data['company_id'] ?? null)->id;

                    return [];
                })->schema([Section::make()->columns(2)->schema($this->commercialFields())])
                ->action(function (array $data, Schema $schema, Action $action): void {
                    Gate::authorize('create', Computer::class);
                    $this->save($schema, $action, fn () => DB::transaction(function () use ($data): void {
                        $company = Company::findOrFail($this->creatingCompanyId);
                        $computer = Computer::create(['company_id' => $company->id, 'name' => $data['name']]);
                        $computer->updateCommercial($data, 0);
                    }));
                }),
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
                }),
        ];
    }
}
