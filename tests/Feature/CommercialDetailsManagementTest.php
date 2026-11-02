<?php

namespace Tests\Feature;

use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Filament\Resources\Workstations\Pages\ManageWorkstations;
use App\Models\Company;
use App\Models\CompanyProduct;
use App\Models\Endpoint;
use App\Models\User;
use App\Models\Workstation;
use App\Services\CoverageService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\RepeatableEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class CommercialDetailsManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        Mail::fake();
        $this->actingAs(User::factory()->create());
    }

    public function test_an_operator_creates_and_edits_company_products_with_exact_prices_and_local_ownership(): void
    {
        $company = $this->company();
        Livewire::test(ManageCompanies::class)->callAction(TestAction::make('edit')->table($company), data: [
            'products' => ['new-product' => ['name' => 'Protezione e add-on', 'annual_price_cents' => '120,55', 'company_id' => $this->company()->id]],
        ])->assertHasNoFormErrors();
        $product = CompanyProduct::query()->sole();
        $this->assertSame($company->id, $product->company_id);
        $this->assertSame(12055, $product->annual_price_cents);

        Livewire::test(ManageCompanies::class)->mountAction(TestAction::make('edit')->table($company))
            ->assertSet('mountedActions.0.data.products.record-'.$product->id.'.annual_price_cents', '120.55')
            ->fillForm(['products' => ['record-'.$product->id => ['name' => 'Protezione aggiornata', 'annual_price_cents' => '99.95']]])
            ->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame(9995, $product->refresh()->annual_price_cents);
        $this->assertSame('Protezione aggiornata', $product->name);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_company_product_omission_and_foreign_record_tampering_cannot_remove_or_move_products(): void
    {
        $company = $this->company();
        $company->update(['contact_name' => 'Referente iniziale']);
        $owned = $company->products()->create(['name' => 'Prodotto locale', 'annual_price_cents' => 10000]);
        $foreign = $this->company()->products()->create(['name' => 'Prodotto esterno', 'annual_price_cents' => 30000]);
        foreach ([[], [
            'record-'.$owned->id => ['name' => $owned->name, 'annual_price_cents' => '100.00'],
            'record-'.$foreign->id => ['name' => 'Alterato', 'annual_price_cents' => '1'],
        ]] as $products) {
            Livewire::test(ManageCompanies::class)->callAction(TestAction::make('edit')->table($company), data: [
                'contact_name' => 'Non salvare', 'products' => $products,
            ])->assertHasFormErrors(['products']);
        }
        $this->assertDatabaseCount('company_products', 2);
        $this->assertSame('Referente iniziale', $company->refresh()->contact_name);
        $this->assertSame('Prodotto esterno', $foreign->refresh()->name);
        $this->assertSame($company->id, $owned->refresh()->company_id);
    }

    public function test_a_manual_workstation_accepts_product_description_activation_and_notes_without_gravityzone(): void
    {
        $company = $this->company();
        $product = $company->products()->create(['name' => 'Edizione manuale', 'annual_price_cents' => 8500]);
        Livewire::test(ManageWorkstations::class)->callAction('create', data: [
            'company_id' => $company->id, 'name' => 'PC ufficio', 'company_product_id' => $product->id,
            'commercial_description' => 'Protezione completa con add-on', 'installed_on' => '2026-04-20', 'notes' => 'Installato manualmente',
        ])->assertHasNoFormErrors();
        $local = Workstation::query()->sole();
        $this->assertSame($product->id, $local->company_product_id);
        $this->assertSame('Protezione completa con add-on', $local->commercial_description);
        $this->assertSame('2026-04-20', $local->installed_on->toDateString());
        $this->assertSame('Installato manualmente', $local->notes);
        $this->assertNull($local->endpoint_id);
        $this->assertDatabaseCount('coverages', 0);
        Livewire::test(ManageWorkstations::class)->searchTable('Protezione completa')->assertCanSeeTableRecords([$local]);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_product_choices_are_scoped_to_the_real_company_even_when_the_disabled_company_field_is_tampered(): void
    {
        $company = $this->company();
        $foreign = $this->company()->products()->create(['name' => 'Prodotto altra azienda', 'annual_price_cents' => 5000]);
        Livewire::test(ManageWorkstations::class)->callAction('create', data: [
            'company_id' => $company->id, 'name' => 'PC vietato', 'company_product_id' => $foreign->id,
        ])->assertHasFormErrors(['company_product_id']);
        $local = Workstation::query()->create(['company_id' => $company->id, 'name' => 'PC locale']);
        Livewire::test(ManageWorkstations::class)->mountAction(TestAction::make('edit')->table($local))
            ->set('mountedActions.0.data.company_id', $foreign->company_id)->fillForm(['company_product_id' => $foreign->id])
            ->callMountedAction()->assertHasFormErrors(['company_product_id']);
        $this->assertNull($local->refresh()->company_product_id);
        $this->assertSame($company->id, $local->company_id);
    }

    public function test_details_keep_technical_fields_and_distinct_commercial_history_read_only_without_duplicate_periods(): void
    {
        $company = $this->company();
        $local = Workstation::query()->create(['company_id' => $company->id, 'name' => 'PC commerciale']);
        $target = Workstation::query()->create(['company_id' => $company->id, 'name' => 'PC sostitutivo']);
        $endpoint = new Endpoint;
        $endpoint->forceFill(['company_id' => $company->id, 'gravityzone_id' => 'endpoint-test', 'name' => 'Hostname tecnico', 'is_present' => true, 'last_seen_at' => now()])->save();
        $local->linkEndpoint($endpoint);
        $service = app(CoverageService::class);
        $coverage = $service->create([
            'company_id' => $company->id, 'workstation_ids' => [$local->id], 'starts_on' => '2026-04-20', 'ends_on' => '2027-04-20',
            'amount' => '85,50', 'invoice_reference' => 'FT 123', 'quote_reference' => 'PR 456',
        ])->sole();
        $service->transfer($coverage, $target);
        $service->transfer($coverage->fresh(), $local);
        $service->transfer($coverage->fresh(), $target);
        $page = Livewire::test(ManageWorkstations::class)->mountAction(TestAction::make('edit')->table($local));
        $details = $page->instance()->mountedActionSchema0->toHtml();
        foreach (['Dati commerciali locali', 'Dati tecnici GravityZone', 'Scadenza tecnica aziendale', '31/12/2026', '20/04/2027', 'FT 123', 'PR 456', 'Hostname tecnico', 'PC sostitutivo'] as $text) {
            $this->assertStringContainsString($text, $details);
        }
        $history = collect($page->instance()->mountedActionSchema0->getFlatComponents())->first(fn ($component): bool => $component instanceof RepeatableEntry);
        $this->assertCount(1, $history->getState());
        $page->fillForm(['commercial_description' => 'Descrizione locale aggiornata', 'installed_on' => '2026-05-01'])
            ->set('mountedActions.0.data.endpoint_id', null)->set('mountedActions.0.data.company_id', $this->company()->id)
            ->set('mountedActions.0.data.commercial_coverages', [['id' => $coverage->id, 'price_cents' => 1, 'ends_on' => '2099-01-01']])
            ->set('mountedActions.0.data.endpoint.name', 'Manipolato')->set('mountedActions.0.data.company.technical_expires_on', '2099-01-01')
            ->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame($endpoint->id, $local->refresh()->endpoint_id);
        $this->assertSame($company->id, $local->company_id);
        $this->assertSame('Hostname tecnico', $endpoint->refresh()->name);
        $this->assertSame('2026-12-31', $company->refresh()->technical_expires_on->toDateString());
        $this->assertSame(8550, $coverage->refresh()->price_cents);
        $this->assertSame('2027-04-20', $coverage->ends_on->toDateString());
        Livewire::test(ManageWorkstations::class)->searchTable('Hostname tecnico')->assertCanSeeTableRecords([$local])->assertCanNotSeeTableRecords([$target]);
    }

    public function test_invalid_annual_price_and_installation_date_are_rejected(): void
    {
        $company = $this->company();
        Livewire::test(ManageCompanies::class)->callAction(TestAction::make('edit')->table($company), data: [
            'products' => ['new-product' => ['name' => 'Prezzo non valido', 'annual_price_cents' => '100.555']],
        ])->assertHasFormErrors(['products.new-product.annual_price_cents']);
        Livewire::test(ManageWorkstations::class)->callAction('create', data: [
            'company_id' => $company->id, 'name' => 'PC non valido', 'installed_on' => 'not-a-date',
        ])->assertHasFormErrors(['installed_on']);
        $this->assertDatabaseCount('company_products', 0);
        $this->assertDatabaseCount('workstations', 0);
    }

    public function test_company_navigation_opens_only_its_local_workstations_and_disabled_users_cannot_save_products(): void
    {
        $company = $this->company();
        $owned = Workstation::query()->create(['company_id' => $company->id, 'name' => 'PC della prima azienda']);
        Workstation::query()->create(['company_id' => $this->company()->id, 'name' => 'PC della seconda azienda']);
        $page = Livewire::test(ManageCompanies::class);
        $action = $page->instance()->getAction([['name' => 'workstations', 'context' => ['table' => true, 'recordKey' => $company->id]]]);
        $url = $action->getUrl();
        $this->get($url)->assertOk()->assertSee($owned->name)->assertDontSee('PC della seconda azienda');
        $editing = Livewire::test(ManageCompanies::class)->mountAction(TestAction::make('edit')->table($company))
            ->fillForm(['products' => ['new-product' => ['name' => 'Non salvare', 'annual_price_cents' => '99']]]);
        $user = auth()->user();
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh());
        $editing->callMountedAction()->assertForbidden();
        $this->assertDatabaseCount('company_products', 0);
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-'.Company::query()->count(), 'name' => 'Azienda test '.Company::query()->count(), 'last_seen_at' => now(), 'is_present' => true, 'technical_expires_on' => '2026-12-31'])->save();

        return $company;
    }
}
