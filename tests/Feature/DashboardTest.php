<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Licenze;
use App\Models\Company;
use App\Models\Computer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Js;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->travelTo(new \DateTimeImmutable('2026-11-03 12:00 Europe/Rome'));
        Http::preventStrayRequests();
        Mail::fake();
    }

    public function test_dashboard_requires_an_active_internal_user_on_direct_and_livewire_access(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/admin/login');
        Livewire::test(Dashboard::class)->assertForbidden();

        foreach (array_keys(User::ROLES) as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user);
            $this->get('/admin/dashboard')->assertOk()->assertSee('Dashboard');
            $page = Livewire::test(Dashboard::class)->assertOk();

            $user->update(['is_active' => false]);
            $this->actingAs($user->fresh());
            $this->get('/admin/dashboard')->assertForbidden();
            Livewire::test(Dashboard::class)->assertForbidden();
            $page->call('$refresh')->assertForbidden();
        }
    }

    public function test_technical_expiry_boundaries_are_independent_of_commercial_statuses(): void
    {
        $this->actingAs(User::factory()->create());
        $expired = $this->company(['technical_expires_on' => '2026-11-02']);
        $today = $this->company(['technical_expires_on' => '2026-11-03']);
        $boundary = $this->company(['technical_expires_on' => '2026-12-03']);
        $active = $this->company(['technical_expires_on' => '2026-12-04']);
        $this->company();
        $this->computer($expired, ['activated_on' => '2026-01-01', 'expires_on' => '2027-01-01']);
        foreach ([
            [],
            ['expires_on' => '2026-11-02'],
            ['activated_on' => '2026-01-01'],
            ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-02'],
            ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-03'],
            ['activated_on' => '2026-01-01', 'expires_on' => '2026-12-03'],
            ['activated_on' => '2026-01-01', 'expires_on' => '2026-12-04'],
            ['activated_on' => '2026-11-04', 'expires_on' => '2026-12-01'],
        ] as $attributes) {
            $this->computer($active, $attributes);
        }

        $page = Livewire::test(Dashboard::class);
        $this->assertSame(['total' => 5, 'expired' => 1, 'expiring' => 2],
            array_map('intval', (array) $page->viewData('companyTotals')));
        $this->assertSame(['Attiva' => 2, 'In scadenza' => 2, 'Scaduta' => 1, 'Da completare' => 3, 'Da attivare' => 1], $page->viewData('statuses'));
        $this->assertSame([$today->id, $boundary->id], $page->viewData('companies')->pluck('id')->all());
    }

    public function test_absent_computers_are_not_counted_and_remote_company_moves_keep_their_local_ownership_and_dates(): void
    {
        $this->actingAs(User::factory()->create());
        $local = $this->company(['technical_expires_on' => '2026-11-03', 'is_present' => false]);
        $remote = $this->company(['technical_expires_on' => '2026-11-03']);
        $this->computer($local, [
            'gravityzone_id' => 'moved-computer', 'gravityzone_company_id' => $remote->id,
            'activated_on' => '2026-01-01', 'expires_on' => '2026-11-03',
        ]);
        $this->computer($local, [
            'gravityzone_id' => 'deleted-computer', 'gravityzone_company_id' => $local->id,
            'is_present' => false, 'activated_on' => '2026-01-01', 'expires_on' => '2026-11-03',
        ]);

        $page = Livewire::test(Dashboard::class);
        $companies = $page->viewData('companies')->keyBy('id');
        $this->assertSame(1, (int) $companies[$local->id]->computers_count);
        $this->assertSame(0, (int) $companies[$remote->id]->computers_count);
        $this->assertSame(1, $page->viewData('statuses')['In scadenza']);
        $this->assertSame(1, $page->viewData('months')['2026-11']['count']);
    }

    public function test_six_month_chart_crosses_year_end_and_counts_known_future_expiries_even_without_activation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo(new \DateTimeImmutable('2026-12-31 23:30 Europe/Rome'));
        $company = $this->company();
        foreach (['2026-12-30', '2026-12-31', '2027-01-01', '2027-01-01', '2027-05-31', '2027-06-01', '2027-06-30', '2027-07-01', null] as $expiry) {
            $this->computer($company, ['expires_on' => $expiry]);
        }

        $page = Livewire::test(Dashboard::class)->set('period', '6m');
        $this->assertSame([
            '2026-12' => 1, '2027-01' => 2, '2027-02' => 0,
            '2027-03' => 0, '2027-04' => 0, '2027-05' => 1, '2027-06' => 2,
        ], array_map(fn (array $month): int => $month['count'], $page->viewData('months')));
        $this->assertSame(9, $page->viewData('statuses')['Da completare']);

        $this->travelTo(new \DateTimeImmutable('2027-01-01 00:30 Europe/Rome'));
        $page->call('$refresh');
        $this->assertSame('2027-01-01', $page->viewData('today')->toDateString());
        $this->assertSame('2027-01', array_key_first($page->viewData('months')));
    }

    public function test_empty_dashboard_has_zero_counts_without_errors(): void
    {
        $this->actingAs(User::factory()->create());
        $page = Livewire::test(Dashboard::class)->assertOk();

        $this->assertSame(0, (int) $page->viewData('companyTotals')->total);
        $this->assertSame(0, array_sum($page->viewData('statuses')));
        $this->assertSame(0, array_sum(array_column($page->viewData('months'), 'count')));
        $this->assertCount(2, $page->viewData('months'));
        $this->assertSame(0, $page->viewData('companies')->total());
    }

    public function test_company_pagination_is_stable_and_escapes_names(): void
    {
        $this->actingAs(User::factory()->create());
        $ids = [];
        for ($i = 0; $i < 8; $i++) {
            $ids[] = $this->company(['name' => 'Stessa azienda', 'technical_expires_on' => '2026-11-03'])->id;
        }
        $unsafeName = '<img src=x onerror=alert(1)>';
        $last = $this->company(['name' => $unsafeName, 'technical_expires_on' => '2026-11-04']);
        $page = Livewire::test(Dashboard::class);
        $this->assertSame(array_slice($ids, 0, 6), $page->viewData('companies')->pluck('id')->all());
        $this->assertSame(9, $page->viewData('companies')->total());

        $page->call('nextPage')->assertSee($unsafeName)->assertDontSee($unsafeName, false);
        $this->assertSame([...array_slice($ids, 6), $last->id], $page->viewData('companies')->pluck('id')->all());
    }

    public function test_dashboard_reads_local_data_without_writes_external_requests_or_per_company_queries(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 8; $i++) {
            $this->computer($this->company(['technical_expires_on' => '2026-11-03']));
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        Livewire::test(Dashboard::class)->assertOk();
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query);
        }
        $domainQueries = $queries->filter(fn (string $query): bool => preg_match('/\b(companies|computers)\b/', $query) === 1);
        $this->assertLessThanOrEqual(4, $domainQueries->count());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_company_links_select_licenses_and_invalid_company_parameters_are_ignored(): void
    {
        $this->actingAs(User::factory()->create());
        $company = $this->company(['technical_expires_on' => '2026-11-03']);
        $computer = $this->computer($company);
        $other = $this->computer($this->company());
        Livewire::test(Dashboard::class)->assertSee(Licenze::getUrl(['company' => $company->id]), false);
        Livewire::withQueryParams(['company' => $company->id])->test(Licenze::class)
            ->assertSet('data.company_id', $company->id)
            ->assertCanSeeTableRecords([$computer])->assertCanNotSeeTableRecords([$other]);

        foreach ([null, 'invalid', '1 OR 1=1', -1, 0, 999999, [$company->id]] as $invalid) {
            Livewire::withQueryParams(['company' => $invalid])->test(Licenze::class)
                ->assertSet('data.company_id', null)->assertCountTableRecords(0);
        }
    }

    #[DataProvider('expiryHorizons')]
    public function test_selected_horizon_includes_the_last_day_for_companies_and_computers(string $today, string $period, string $horizon): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo(new \DateTimeImmutable($today.' 12:00 Europe/Rome'));
        $boundary = $this->company(['technical_expires_on' => $horizon]);
        $followingDay = (new \DateTimeImmutable($horizon))->modify('+1 day')->format('Y-m-d');
        $outside = $this->company(['technical_expires_on' => $followingDay]);
        $this->computer($boundary, ['activated_on' => $today, 'expires_on' => $horizon]);
        $this->computer($outside, ['expires_on' => $followingDay]);

        $page = Livewire::test(Dashboard::class)->set('period', $period)->set('companyState', $period);
        $this->assertSame($horizon, $page->viewData('horizon')->toDateString());
        $this->assertSame(1, (int) $page->viewData('companyTotals')->expiring);
        $this->assertSame([$boundary->id], $page->viewData('companies')->pluck('id')->all());
        $this->assertSame(1, array_sum(array_column($page->viewData('months'), 'count')));
        $this->assertSame(substr($horizon, 0, 7), array_key_last($page->viewData('months')));
        $this->assertSame(in_array($period, ['15', '30'], true) ? 1 : 0, $page->viewData('statuses')['In scadenza']);
    }

    public static function expiryHorizons(): array
    {
        return [
            ['2026-08-31', '15', '2026-09-15'],
            ['2026-08-31', '30', '2026-09-30'],
            ['2026-08-31', '60', '2026-10-30'],
            ['2026-08-31', '3m', '2026-11-30'],
            ['2026-11-30', '3m', '2027-02-28'],
            ['2026-08-31', '6m', '2027-02-28'],
            ['2026-08-31', '1y', '2027-08-31'],
            ['2028-02-29', '1y', '2029-02-28'],
        ];
    }

    public function test_company_search_and_state_reset_pagination_without_changing_global_totals(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 8; $i++) {
            $this->company(['technical_expires_on' => '2026-11-02']);
        }
        $target = $this->company(['name' => 'Ricerca dedicata', 'technical_expires_on' => '2026-12-03']);
        $future = $this->company(['technical_expires_on' => '2027-01-01']);
        $unknown = $this->company();
        $page = Livewire::test(Dashboard::class)->set('companyState', 'all')->call('nextPage')->set('companySearch', 'Ricerca');
        $this->assertSame(1, $page->viewData('companies')->currentPage());
        $this->assertSame([$target->id], $page->viewData('companies')->pluck('id')->all());
        $this->assertSame(['total' => 11, 'expired' => 8, 'expiring' => 1],
            array_map('intval', (array) $page->viewData('companyTotals')));

        $page->set('companySearch', '')->call('nextPage')->set('companyState', 'expired');
        $this->assertSame(1, $page->viewData('companies')->currentPage());
        $this->assertSame(8, $page->viewData('companies')->total());
        $page->call('nextPage')->set('companyState', '3m');
        $this->assertSame(1, $page->viewData('companies')->currentPage());
        $this->assertSame([$target->id, $future->id], $page->viewData('companies')->pluck('id')->all());
        $page->set('companyState', 'all')->set('companySearch', $unknown->name);
        $this->assertSame([$unknown->id], $page->viewData('companies')->pluck('id')->all());
        $page->set('companySearch', 'Nessun risultato');
        $this->assertSame(0, $page->viewData('companies')->total());
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_company_and_chart_periods_are_independent_and_only_company_filters_reset_pagination(): void
    {
        $this->actingAs(User::factory()->create());
        $expired = $this->company(['technical_expires_on' => '2026-11-02']);
        $unknown = $this->company(['name' => 'Senza data tecnica']);
        $today = $this->company(['technical_expires_on' => '2026-11-03']);
        for ($i = 0; $i < 7; $i++) {
            $this->company(['technical_expires_on' => '2026-11-18']);
        }
        $outside = $this->company(['technical_expires_on' => '2026-11-19']);
        $this->computer($today, ['expires_on' => '2027-01-01']);

        $page = Livewire::test(Dashboard::class)->set('companyState', '15');
        $this->assertSame(8, $page->viewData('companies')->total());
        $this->assertSame(8, (int) $page->viewData('companyTotals')->expiring);
        $this->assertSame('2026-11-18', $page->viewData('companyHorizon')->toDateString());
        $page->call('nextPage');
        $ids = $page->viewData('companies')->pluck('id')->all();
        $page->set('period', '1y');
        $this->assertSame(2, $page->viewData('companies')->currentPage());
        $this->assertSame($ids, $page->viewData('companies')->pluck('id')->all());
        $this->assertSame(8, (int) $page->viewData('companyTotals')->expiring);
        $months = $page->viewData('months');
        $this->assertSame(1, $months['2027-01']['count']);
        $statuses = $page->viewData('statuses');

        $page->set('companyState', '60');
        $this->assertSame(1, $page->viewData('companies')->currentPage());
        $this->assertSame(9, $page->viewData('companies')->total());
        $this->assertSame($months, $page->viewData('months'));
        $this->assertSame($statuses, $page->viewData('statuses'));
        $page->set('companyState', 'expired');
        $this->assertSame([$expired->id], $page->viewData('companies')->pluck('id')->all());
        $page->set('companyState', 'all');
        $this->assertSame(11, $page->viewData('companies')->total());
        $page->set('companySearch', $unknown->name);
        $this->assertSame([$unknown->id], $page->viewData('companies')->pluck('id')->all());
        $page->set('companySearch', $outside->name);
        $this->assertSame([$outside->id], $page->viewData('companies')->pluck('id')->all());
    }

    #[DataProvider('companyExpiryBadges')]
    public function test_company_expiry_badges_use_days_months_years_and_an_exact_date_tooltip(?string $expiry, string $label): void
    {
        $this->actingAs(User::factory()->create());
        $company = $this->company(['technical_expires_on' => $expiry]);
        $page = Livewire::withQueryParams(['companyState' => 'all'])->test(Dashboard::class)
            ->assertSee($label);
        if ($expiry !== null) {
            $page->assertSee('x-tooltip', false)
                ->assertSee((string) Js::from($company->technical_expires_on->format('d/m/Y')), false);
        }
    }

    public static function companyExpiryBadges(): array
    {
        return [
            [null, 'Data mancante'],
            ['2026-11-03', 'Scade oggi'],
            ['2026-11-04', 'Tra 1 giorno'],
            ['2026-11-18', 'Tra 15 giorni'],
            ['2026-12-02', 'Tra 29 giorni'],
            ['2026-12-03', 'Tra 1 mese'],
            ['2027-05-03', 'Tra 6 mesi'],
            ['2027-11-02', 'Tra 11 mesi'],
            ['2027-11-03', 'Tra 1 anno'],
            ['2030-07-14', 'Tra 3 anni'],
            ['2026-11-02', 'Scaduta da 1 giorno'],
            ['2026-10-03', 'Scaduta da 1 mese'],
            ['2025-11-03', 'Scaduta da 1 anno'],
        ];
    }

    public function test_dashboard_validates_query_parameters_and_livewire_filters(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::withQueryParams(['period' => '3m', 'companyState' => 'all'])->test(Dashboard::class)
            ->assertSet('period', '3m')->assertSet('companyState', 'all');
        foreach (['period' => 'forever', 'companyState' => 'invalid', 'companySearch' => str_repeat('x', 256)] as $field => $value) {
            Livewire::test(Dashboard::class)->set($field, $value)->assertStatus(422);
        }
        foreach ([['period' => 'forever'], ['period' => ['30']], ['companyState' => 'invalid'], ['companyState' => ['30']]] as $query) {
            $this->get('/admin/dashboard?'.http_build_query($query))->assertStatus(422);
        }
    }

    private function company(array $attributes = []): Company
    {
        $company = new Company;
        $company->forceFill(array_replace([
            'gravityzone_id' => 'company-'.Company::count(), 'name' => 'Azienda '.Company::count(), 'last_seen_at' => now(),
        ], $attributes))->save();

        return $company;
    }

    private function computer(Company $company, array $attributes = []): Computer
    {
        $computer = new Computer;
        $computer->forceFill(array_replace(['company_id' => $company->id, 'name' => 'PC '.Computer::count()], $attributes))->save();

        return $computer;
    }
}
