<?php

namespace Tests\Feature;

use App\Filament\Pages\Licenze;
use App\Livewire\CompanyLicenses;
use App\Models\Company;
use App\Models\Computer;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class LicenzeFilterPersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
    }

    public function test_selection_and_table_filters_survive_a_new_page_instance_and_can_be_cleared(): void
    {
        $company = $this->company('Azienda scelta', 'Endpoint Security');
        Livewire::test(Licenze::class)->set('data.license', 'Endpoint Security')->set('data.company_id', $company->id)
            ->filterTable('status', 'missing')->filterTable('expiry', ['from' => '2026-01-01', 'until' => '2027-01-01']);
        $page = Livewire::test(Licenze::class)->assertSet('data.company_id', $company->id)->assertSet('data.license', 'Endpoint Security')
            ->assertSet('tableFilters.status.value', 'missing')->assertSet('tableFilters.expiry.from', '2026-01-01');
        $page->removeTableFilters()->set('data.license', null);
        Livewire::test(Licenze::class)->assertSet('data.company_id', 'all')->assertSet('data.license', null)
            ->assertSet('tableFilters.status.value', null)->assertSet('tableFilters.expiry.from', null);
        Http::assertNothingSent();
    }

    public function test_all_company_tables_restore_their_own_filters_without_changing_the_page_selection(): void
    {
        $first = $this->company('Prima', 'Endpoint Security');
        $second = $this->company('Seconda', 'Endpoint Security');
        Computer::create(['company_id' => $first->id, 'name' => 'Prima vuota']);
        Computer::create(['company_id' => $second->id, 'name' => 'Seconda vuota']);
        Livewire::test(Licenze::class)->set('data.license', 'Endpoint Security');
        Livewire::test(CompanyLicenses::class, ['companyId' => $first->id])->filterTable('status', 'expired');
        Livewire::test(CompanyLicenses::class, ['companyId' => $second->id])->filterTable('status', 'missing');
        Livewire::test(Licenze::class)->assertSet('data.company_id', 'all')->assertSet('data.license', 'Endpoint Security')
            ->assertDontSee('Prima vuota')->assertSee('Seconda vuota');
        Livewire::test(CompanyLicenses::class, ['companyId' => $first->id])->assertSet('tableFilters.status.value', 'expired');
        Livewire::test(CompanyLicenses::class, ['companyId' => $second->id])->assertSet('tableFilters.status.value', 'missing');
        Http::assertNothingSent();
    }

    public function test_dashboard_navigation_overrides_saved_selection_and_filters_but_regular_refresh_restores_them(): void
    {
        $first = $this->company('Prima', 'Prodotto A');
        $second = $this->company('Seconda', 'Prodotto B');
        Livewire::test(Licenze::class)->set('data.license', 'Prodotto A')->set('data.company_id', $first->id)->filterTable('status', 'expired');
        Livewire::withQueryParams(['company' => $first->id, 'license' => 'Prodotto A', 'status' => 'missing'])->test(Licenze::class)
            ->assertSet('data.license', 'Prodotto A')->assertSet('tableFilters.status.value', 'expired');
        Livewire::withQueryParams(['company' => $second->id, 'status' => 'missing'])->test(Licenze::class)
            ->assertSet('data.company_id', $second->id)->assertSet('data.license', null)->assertSet('tableFilters.status.value', 'missing');
        Http::assertNothingSent();
    }

    private function company(string $name, string $product): Company
    {
        $company = new Company;
        $company->forceFill(['name' => $name, 'technical_product' => $product, 'gravityzone_id' => 'company-'.Company::count(), 'last_seen_at' => now()])->save();

        return $company;
    }
}
