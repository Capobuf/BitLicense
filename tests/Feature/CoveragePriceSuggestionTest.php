<?php

namespace Tests\Feature;

use App\Filament\Resources\Coverages\Pages\ManageCoverages;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\Coverage;
use App\Models\User;
use App\Models\Workstation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CoveragePriceSuggestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_shared_company_product_suggests_an_annual_price_and_operator_can_override_it(): void
    {
        $company = $this->company();
        $product = $company->products()->create(['name' => 'Prodotto con add-on', 'annual_price_cents' => 7990]);
        $one = $this->workstation($company, $product);
        $two = $this->workstation($company, $product);
        $page = Livewire::test(ManageCoverages::class)->mountAction('create')
            ->set('mountedActions.0.data.company_id', $company->id)
            ->set('mountedActions.0.data.workstation_ids', [$one->id, $two->id])
            ->assertActionDataSet(['amount' => '79.90', 'starts_on' => null, 'ends_on' => null]);
        $page->set('mountedActions.0.data.amount', '75,51')
            ->set('mountedActions.0.data.workstation_ids', [$one->id])->assertActionDataSet(['amount' => '75,51'])
            ->set('mountedActions.0.data.workstation_ids', [$one->id, $two->id])->assertActionDataSet(['amount' => '75,51']);
        $page->fillForm(['starts_on' => '2026-04-20', 'ends_on' => '2027-04-20', 'amount' => '75,51'])
            ->callMountedAction()->assertHasNoFormErrors()->assertNotified('Coperture create');
        $this->assertSame([7551, 7551], Coverage::query()->pluck('price_cents')->all());
        $this->assertSame(7990, $product->refresh()->annual_price_cents);
        Http::assertNothingSent();
    }

    public function test_missing_or_different_products_require_an_explicit_price_and_company_change_resets_selection(): void
    {
        $company = $this->company();
        $product = $company->products()->create(['name' => 'Edizione A', 'annual_price_cents' => 10000]);
        $other = $company->products()->create(['name' => 'Edizione B', 'annual_price_cents' => 10000]);
        $one = $this->workstation($company, $product);
        $two = $this->workstation($company, $other);
        $manual = $this->workstation($company);
        $page = Livewire::test(ManageCoverages::class)->mountAction('create')
            ->set('mountedActions.0.data.company_id', $company->id)
            ->set('mountedActions.0.data.workstation_ids', [$one->id])->assertActionDataSet(['amount' => '100.00']);
        foreach ([[$one->id, $two->id], [$manual->id], [$one->id, $manual->id], []] as $ids) {
            $page->set('mountedActions.0.data.workstation_ids', $ids)->assertActionDataSet(['amount' => null]);
        }
        $page->set('mountedActions.0.data.workstation_ids', [$one->id])
            ->set('mountedActions.0.data.company_id', $this->company()->id)
            ->assertActionDataSet(['amount' => null, 'workstation_ids' => []]);
        $this->assertDatabaseCount('coverages', 0);
    }

    public function test_changing_the_customary_price_does_not_rewrite_existing_coverage_and_edit_keeps_its_chosen_amount(): void
    {
        $company = $this->company();
        $product = $company->products()->create(['name' => 'Prodotto locale', 'annual_price_cents' => 7990]);
        $workstation = $this->workstation($company, $product);
        Livewire::test(ManageCoverages::class)->callAction('create', data: [
            'company_id' => $company->id, 'workstation_ids' => [$workstation->id],
            'starts_on' => '2026-04-20', 'ends_on' => '2027-04-20', 'amount' => '70',
        ])->assertHasNoFormErrors();
        $coverage = Coverage::query()->sole();
        $product->update(['annual_price_cents' => 9990]);
        Livewire::test(ManageCoverages::class)->mountAction(TestAction::make('edit')->table($coverage))
            ->assertActionDataSet(['amount' => '70.00']);
        Livewire::test(ManageCoverages::class)->mountAction('create')
            ->set('mountedActions.0.data.company_id', $company->id)
            ->set('mountedActions.0.data.workstation_ids', [$workstation->id])->assertActionDataSet(['amount' => '99.90']);
        $this->assertSame(7000, $coverage->refresh()->price_cents);
        $this->assertSame(1, $coverage->assignments()->count());
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['name' => 'Azienda test', 'gravityzone_id' => 'company-'.Company::query()->count(), 'is_present' => true, 'last_seen_at' => now()])->save();

        return $company;
    }

    private function workstation(Company $company, ?CompanyProduct $product = null): Workstation
    {
        return Workstation::query()->create(['company_id' => $company->id, 'name' => 'PC locale', 'company_product_id' => $product?->id]);
    }
}
