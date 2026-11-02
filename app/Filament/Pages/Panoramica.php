<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Endpoints\EndpointResource;
use App\Filament\Resources\Workstations\WorkstationResource;
use App\Models\Company;
use App\Services\ExpiryOverview;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class Panoramica extends Dashboard implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Panoramica';

    protected ?string $subheading = 'Scadenzario e avvisi interni aggiornati dai dati locali. Nessun invio automatico ai clienti. I casi senza data, senza copertura attuale o da verificare restano visibili in ogni periodo.';

    private const TYPES = ['technical' => 'Tecnica aziendale', 'commercial' => 'Commerciale postazione', 'endpoint' => 'Endpoint da verificare'];

    private const STATUSES = ['expired' => 'Scaduta', 'expiring' => 'In scadenza entro 30 giorni', 'active' => 'Attiva oltre 30 giorni', 'missing' => 'Dato o copertura mancante', 'gap' => 'Non coperta oggi', 'verify' => 'Da verificare'];

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessPanel(Filament::getPanel('admin')) ?? false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table->heading('Scadenzario e avvisi interni')->stackedOnMobile()
            ->records(function (?array $filters, ?string $search, int $page, int|string $recordsPerPage): LengthAwarePaginator {
                $companyId = $filters['company_id']['value'] ?? null;
                $type = $filters['type']['value'] ?? null;
                $status = $filters['status']['value'] ?? null;
                $period = $filters['period']['value'] ?? '30';
                $horizon = in_array((string) $period, ['30', '60', '90'], true) ? today()->addDays((int) $period)->toDateString() : null;
                $search = Str::lower(trim($search ?? ''));
                $rows = app(ExpiryOverview::class)->rows(filled($companyId) ? (int) $companyId : null)
                    ->filter(fn (array $row): bool => (blank($type) || $row['type'] === $type)
                        && (blank($status) || $row['status'] === $status)
                        && ($horizon === null || $row['expires_on'] === null || in_array($row['status'], ['missing', 'gap', 'verify'], true) || $row['expires_on'] <= $horizon)
                        && ($search === '' || Str::contains(Str::lower($row['company'].' '.$row['name'].' '.$row['detail']), $search)))
                    ->sortBy(fn (array $row): array => [
                        ['expired' => 0, 'gap' => 0, 'expiring' => 1, 'missing' => 2, 'verify' => 2, 'active' => 3][$row['status']],
                        $row['expires_on'] ?? '9999-12-31', $row['name'], $row['id'],
                    ]);
                $perPage = max(1, (int) $recordsPerPage);

                return (new LengthAwarePaginator($rows->forPage($page, $perPage), $rows->count(), $perPage, $page, [
                    'pageName' => $this->getTablePaginationPageName(),
                ]))->onEachSide(0);
            })
            ->columns([
                TextColumn::make('name')->label('Azienda o postazione')->searchable()->wrap()
                    ->description(fn (array $record): string => $record['company']),
                TextColumn::make('type')->label('Tipo di dato')->badge()->formatStateUsing(fn (string $state): string => self::TYPES[$state])
                    ->color(fn (string $state): string => $state === 'technical' ? 'info' : ($state === 'commercial' ? 'primary' : 'gray'))->wrap(),
                TextColumn::make('expires_on')->label('Data di riferimento')->date()->placeholder('Non disponibile'),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state): string => self::STATUSES[$state])
                    ->color(fn (string $state): string => match ($state) {
                        'expired', 'gap' => 'danger', 'active' => 'success', default => 'warning',
                    })->wrap(),
                TextColumn::make('detail')->label('Indicazione operativa')->wrap(),
            ])
            ->filters([
                SelectFilter::make('company_id')->label('Azienda')->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                SelectFilter::make('type')->label('Tipo di dato')->options(self::TYPES),
                SelectFilter::make('status')->label('Stato')->options(self::STATUSES)->placeholder('Tutti gli stati'),
                SelectFilter::make('period')->label('Scadenze')->options([
                    '30' => 'Scadute ed entro 30 giorni', '60' => 'Scadute ed entro 60 giorni',
                    '90' => 'Scadute ed entro 90 giorni', 'all' => 'Qualsiasi data',
                ])->default('30')->selectablePlaceholder(false),
            ])
            ->recordActions([
                Action::make('open')->label(fn (array $record): string => match ($record['type']) {
                    'technical' => 'Mostra in Aziende', 'commercial' => 'Mostra in Postazioni', default => 'Mostra in Endpoint',
                })->url(fn (array $record): string => match ($record['type']) {
                    'technical' => CompanyResource::getUrl('index', ['search' => $record['company']]),
                    'commercial' => WorkstationResource::getUrl('index', ['search' => $record['name'], 'filters' => ['company' => ['value' => $record['company_id']]]]),
                    default => EndpointResource::getUrl('index', ['search' => $record['name'], 'filters' => ['company' => ['value' => $record['company_id']]]]),
                }),
            ])
            ->paginated([15, 30, 60])->defaultPaginationPageOption(15)
            ->emptyStateHeading('Nessun avviso per i filtri selezionati')
            ->emptyStateDescription('Amplia il periodo o controlla i filtri. Le date mancanti non vengono ricavate dalla scadenza tecnica GravityZone.');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('refresh')->label('Aggiorna avvisi')->action(fn () => $this->flushCachedTableRecords())];
    }
}
