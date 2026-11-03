<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Computer;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Validation\Rule;
use Livewire\WithPagination;

class Dashboard extends Page
{
    use WithPagination;

    public const PERIODS = ['30' => '30 giorni', '60' => '60 giorni', '90' => '90 giorni', '6m' => '6 mesi', '1y' => '1 anno'];

    public const COMPANY_STATES = ['attention' => 'Scadute e in scadenza', 'expiring' => 'In scadenza nel periodo', 'expired' => 'Scadute', 'all' => 'Tutte le aziende'];

    public string $period = '30';

    public string $companySearch = '';

    public string $companyState = 'attention';

    protected static ?string $title = 'Dashboard';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?int $navigationSort = -3;

    protected string $view = 'filament.pages.dashboard';

    protected ?string $heading = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessPanel(Filament::getPanel('admin')) ?? false;
    }

    public function mount(): void
    {
        $input = request()->only(['period', 'companyState']);
        abort_if(validator($input, [
            'period' => ['string', Rule::in(array_keys(self::PERIODS))],
            'companyState' => ['string', Rule::in(array_keys(self::COMPANY_STATES))],
        ])->fails(), 422);
        $this->fill($input);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['period', 'companySearch', 'companyState'], true)) {
            $this->resetPage();
        }
    }

    protected function rules(): array
    {
        return [
            'period' => ['required', Rule::in(array_keys(self::PERIODS))],
            'companyState' => ['required', Rule::in(array_keys(self::COMPANY_STATES))],
            'companySearch' => ['string', 'max:255'],
        ];
    }

    protected function getViewData(): array
    {
        abort_if(validator($this->only(array_keys($this->rules())), $this->rules())->fails(), 422);
        $today = today('Europe/Rome')->toImmutable();
        $horizon = match ($this->period) {
            '6m' => $today->addMonthsNoOverflow(6),
            '1y' => $today->addYearNoOverflow(),
            default => $today->addDays((int) $this->period),
        };
        $periodLabel = self::PERIODS[$this->period];
        $companyTotals = Company::query()->toBase()->selectRaw(
            'COUNT(*) AS total, SUM(CASE WHEN DATE(technical_expires_on) < ? THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN DATE(technical_expires_on) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS expiring,
            SUM(CASE WHEN technical_expires_on IS NULL THEN 1 ELSE 0 END) AS unknown',
            [$today->toDateString(), $today->toDateString(), $horizon->toDateString()],
        )->first();

        $statuses = array_fill_keys(['Attiva', 'In scadenza', 'Scaduta', 'Da completare', 'Da attivare'], 0);
        $months = [];
        for ($month = $today->startOfMonth(); $month->lte($horizon); $month = $month->addMonth()) {
            $months[$month->format('Y-m')] = [
                'label' => $month->locale('it')->translatedFormat('M Y'), 'count' => 0,
                'from' => $month->max($today)->toDateString(),
                'until' => $month->endOfMonth()->min($horizon)->toDateString(),
            ];
        }

        $periods = Computer::query()->select(['activated_on', 'expires_on'])
            ->selectRaw('COUNT(*) AS total')->groupBy('activated_on', 'expires_on')->get();
        foreach ($periods as $period) {
            $statuses[$period->commercialStatus()] += (int) $period->total;
            $month = $period->expires_on?->format('Y-m');
            if ($period->expires_on?->betweenIncluded($today, $horizon) && isset($months[$month])) {
                $months[$month]['count'] += (int) $period->total;
            }
        }

        $companies = Company::query()
            ->select(['id', 'name', 'technical_expires_on', 'is_present', 'is_suspended', 'sync_error'])
            ->addSelect(['computers_count' => Computer::query()->selectRaw('COUNT(*)')->whereColumn('company_id', 'companies.id')])
            ->when($this->companySearch !== '', fn ($query) => $query->whereLike('name', '%'.$this->companySearch.'%'))
            ->when(in_array($this->companyState, ['attention', 'expiring']), fn ($query) => $query->whereDate('technical_expires_on', '<=', $horizon->toDateString()))
            ->when($this->companyState === 'expiring', fn ($query) => $query->whereDate('technical_expires_on', '>=', $today->toDateString()))
            ->when($this->companyState === 'expired', fn ($query) => $query->whereDate('technical_expires_on', '<', $today->toDateString()))
            ->orderBy('technical_expires_on')->orderBy('name')->orderBy('id')->paginate(6);

        $stats = Schema::make($this)->columns(['default' => 1, 'sm' => 2, 'xl' => 4])->components([
            Stat::make('Aziende', number_format($companyTotals->total, 0, ',', '.'))
                ->description('Mostra tutte le aziende')->icon(Heroicon::OutlinedBuildingOffice2)
                ->url(self::getUrl(['period' => $this->period, 'companyState' => 'all']).'#companies'),
            Stat::make('Computer', number_format(array_sum($statuses), 0, ',', '.'))
                ->description('Apri tutti i computer')->icon(Heroicon::OutlinedComputerDesktop)
                ->url(Licenze::getUrl(['company' => 'all'])),
            Stat::make('Aziende in scadenza', (int) $companyTotals->expiring)
                ->description('Scadenza tecnica · entro '.$periodLabel)->icon(Heroicon::OutlinedClock)->color('warning')
                ->url(self::getUrl(['period' => $this->period, 'companyState' => 'expiring']).'#companies'),
            Stat::make('Aziende scadute', (int) $companyTotals->expired)
                ->description('Scadenza tecnica · prima di oggi')->icon(Heroicon::OutlinedExclamationCircle)->color('danger')
                ->url(self::getUrl(['period' => $this->period, 'companyState' => 'expired']).'#companies'),
        ]);

        return compact('today', 'horizon', 'periodLabel', 'companyTotals', 'statuses', 'months', 'companies', 'stats');
    }
}
