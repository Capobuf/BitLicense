<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Computer;
use BackedEnum;
use Carbon\CarbonImmutable;
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

    public const PERIODS = ['15' => '15 giorni', '30' => '30 giorni', '60' => '60 giorni', '3m' => '3 mesi', '6m' => '6 mesi', '1y' => '1 anno'];

    public const COMPANY_STATES = ['15' => '15 Giorni', '30' => '30 Giorni', '60' => '60 Giorni', '3m' => '3 Mesi', '6m' => '6 Mesi', '1y' => '1 Anno', 'expired' => 'Scadute', 'all' => 'Tutte le aziende'];

    public string $period = '30';

    public string $companySearch = '';

    public string $companyState = '30';

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
        if (in_array($property, ['companySearch', 'companyState'], true)) {
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
        $horizon = $this->expiryHorizon($today, $this->period);
        $companyPeriod = array_key_exists($this->companyState, self::PERIODS) ? $this->companyState : '30';
        $companyHorizon = $this->expiryHorizon($today, $companyPeriod);
        $companyTotals = Company::query()->toBase()->selectRaw(
            'COUNT(*) AS total, SUM(CASE WHEN DATE(technical_expires_on) < ? THEN 1 ELSE 0 END) AS expired,
            SUM(CASE WHEN DATE(technical_expires_on) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS expiring',
            [$today->toDateString(), $today->toDateString(), $companyHorizon->toDateString()],
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
            ->select(['id', 'name', 'technical_license_type', 'technical_expires_on', 'is_present', 'is_suspended', 'sync_error', 'gravityzone_type', 'can_manage_security'])
            ->addSelect(['computers_count' => Computer::query()->selectRaw('COUNT(*)')->whereColumn('company_id', 'companies.id')])
            ->when($this->companySearch !== '', fn ($query) => $query->whereLike('name', '%'.$this->companySearch.'%'))
            ->when(array_key_exists($this->companyState, self::PERIODS), fn ($query) => $query
                ->whereDate('technical_expires_on', '>=', $today->toDateString())
                ->whereDate('technical_expires_on', '<=', $companyHorizon->toDateString()))
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
                ->description('Scadenza tecnica · entro '.self::PERIODS[$companyPeriod])->icon(Heroicon::OutlinedClock)->color('warning')
                ->url(self::getUrl(['period' => $this->period, 'companyState' => $companyPeriod]).'#companies'),
            Stat::make('Aziende scadute', (int) $companyTotals->expired)
                ->description('Scadenza tecnica · prima di oggi')->icon(Heroicon::OutlinedExclamationCircle)->color('danger')
                ->url(self::getUrl(['period' => $this->period, 'companyState' => 'expired']).'#companies'),
        ]);

        return compact('today', 'horizon', 'companyHorizon', 'companyTotals', 'statuses', 'months', 'companies', 'stats');
    }

    private function expiryHorizon(CarbonImmutable $today, string $period): CarbonImmutable
    {
        return match ($period) {
            '3m' => $today->addMonthsNoOverflow(3),
            '6m' => $today->addMonthsNoOverflow(6),
            '1y' => $today->addYearNoOverflow(),
            default => $today->addDays((int) $period),
        };
    }
}
