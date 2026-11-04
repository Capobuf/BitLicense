<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Licenze;
use App\Livewire\CompanyLicenses;
use App\Models\Company;
use App\Models\Computer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $this->travelTo(new \DateTimeImmutable('2026-11-03 12:00 Europe/Rome'));
        Http::preventStrayRequests();
        Mail::fake();
    }

    public function test_cards_and_chart_links_preserve_the_selected_horizon_and_use_clipped_month_ranges(): void
    {
        $company = $this->company();
        foreach (['2026-11-03', '2026-11-02', '2026-12-03', '2027-01-01'] as $expiry) {
            $this->computer($company, ['activated_on' => '2026-01-01', 'expires_on' => $expiry]);
        }
        $this->computer($company);
        $this->computer($company, ['activated_on' => '2026-12-01', 'expires_on' => '2027-01-01']);
        $page = Livewire::test(Dashboard::class)->set('period', '3m')->set('companyState', '60');
        foreach (['all', '60', 'expired'] as $state) {
            $page->assertSee(Dashboard::getUrl(['period' => '3m', 'companyState' => $state]).'#companies');
        }
        $page->assertSee(Licenze::getUrl(['company' => 'all']));
        foreach (['active', 'expiring', 'expired', 'missing', 'future'] as $status) {
            $page->assertSee(Licenze::getUrl(['company' => 'all', 'status' => $status]));
        }
        foreach ([['2026-11-03', '2026-11-30'], ['2026-12-01', '2026-12-31'], ['2027-01-01', '2027-01-31'], ['2027-02-01', '2027-02-03']] as [$from, $until]) {
            $page->assertSee(Licenze::getUrl(['company' => 'all', 'expires_from' => $from, 'expires_until' => $until]));
        }
    }

    public function test_status_links_filter_all_company_tables_and_keep_the_standard_thirty_day_definition(): void
    {
        $first = $this->company();
        $second = $this->company();
        $records = [
            'active' => $this->computer($first, ['activated_on' => '2026-01-01', 'expires_on' => '2026-12-04']),
            'expiring' => $this->computer($second, ['activated_on' => '2026-01-01', 'expires_on' => '2026-12-03']),
            'expired' => $this->computer($first, ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-02']),
            'missing' => $this->computer($second),
            'future' => $this->computer($first, ['activated_on' => '2026-11-04', 'expires_on' => '2026-12-01']),
        ];
        foreach ($records as $status => $expected) {
            $page = Livewire::withQueryParams(['company' => 'all', 'status' => $status])->test(Licenze::class)
                ->assertSet('data.company_id', 'all')->assertSet('tableFilters.status.value', $status)
                ->assertSee($expected->name);
            foreach ($records as $otherStatus => $other) {
                if ($otherStatus !== $status) {
                    $page->assertDontSee($other->name);
                }
            }
        }
        Http::assertNothingSent();
        Mail::assertNothingOutgoing();
    }

    public function test_month_navigation_and_child_tables_include_both_boundaries_and_expiry_only_records(): void
    {
        $company = $this->company();
        $before = $this->computer($company, ['expires_on' => '2026-11-02']);
        $first = $this->computer($company, ['expires_on' => '2026-11-03']);
        $last = $this->computer($company, ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-30']);
        $after = $this->computer($company, ['expires_on' => '2026-12-01']);
        $missing = $this->computer($company);
        $other = $this->computer($this->company(), ['expires_on' => '2026-11-15']);
        $query = ['company' => 'all', 'expires_from' => '2026-11-03', 'expires_until' => '2026-11-30'];
        $page = Livewire::withQueryParams($query)->test(Licenze::class)
            ->assertSet('tableFilters.expiry.from', fn (string $value): bool => substr($value, 0, 10) === '2026-11-03')
            ->assertSet('tableFilters.expiry.until', fn (string $value): bool => substr($value, 0, 10) === '2026-11-30')
            ->assertSee($first->name)->assertSee($last->name)->assertSee($other->name)
            ->assertDontSee($before->name)->assertDontSee($after->name)->assertDontSee($missing->name);

        Livewire::test(CompanyLicenses::class, ['companyId' => $company->id, 'filters' => $page->get('tableFilters')])
            ->assertCanSeeTableRecords([$first, $last])->assertCountTableRecords(2)
            ->assertCanNotSeeTableRecords([$before, $after, $missing, $other])
            ->removeTableFilter('expiry')->assertCountTableRecords(5);
    }

    public function test_malformed_navigation_filters_are_ignored_without_errors_or_broadening_company_scope(): void
    {
        $company = $this->company();
        $one = $this->computer($company, ['expires_on' => '2026-11-03']);
        $two = $this->computer($company, ['expires_on' => '2027-01-01']);
        $foreign = $this->computer($this->company());
        foreach ([
            ['status' => 'invalid'], ['status' => ['expired']],
            ['expires_from' => '2026-11-03'], ['expires_until' => '2026-11-30'],
            ['expires_from' => '2026-02-30', 'expires_until' => '2026-11-30'],
            ['expires_from' => '2026-12-01', 'expires_until' => '2026-11-30'],
            ['expires_from' => ['2026-11-03'], 'expires_until' => '2026-11-30'],
        ] as $invalid) {
            Livewire::withQueryParams(['company' => $company->id] + $invalid)->test(Licenze::class)
                ->assertSet('data.company_id', $company->id)->assertCountTableRecords(2)
                ->assertCanSeeTableRecords([$one, $two])->assertCanNotSeeTableRecords([$foreign]);
        }
    }

    public function test_native_expiry_filter_rejects_invalid_ranges_and_can_be_cleared(): void
    {
        $company = $this->company();
        $first = $this->computer($company, ['expires_on' => '2026-11-03']);
        $last = $this->computer($company, ['expires_on' => '2026-11-30']);
        $this->computer($company, ['expires_on' => '2026-12-01']);
        $page = Livewire::test(CompanyLicenses::class, ['companyId' => $company->id])
            ->filterTable('expiry', ['from' => '2026-11-03', 'until' => '2026-11-30'])
            ->assertCanSeeTableRecords([$first, $last])->assertCountTableRecords(2);
        foreach ([['from' => '2026-12-01', 'until' => '2026-11-30'], ['from' => 'invalid', 'until' => '2026-11-30']] as $invalid) {
            $page->set('tableFilters.expiry', $invalid)->assertCountTableRecords(0);
        }
        $page->removeTableFilter('expiry')->assertCountTableRecords(3);
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-'.Company::count(), 'name' => 'Azienda '.Company::count(), 'last_seen_at' => now()])->save();

        return $company;
    }

    private function computer(Company $company, array $attributes = []): Computer
    {
        $computer = new Computer;
        $computer->forceFill(array_replace(['company_id' => $company->id, 'name' => 'Computer navigabile '.Computer::count()], $attributes))->save();

        return $computer;
    }
}
