<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Licenze;
use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Livewire\CompanyLicenses;
use App\Livewire\CompanyRenewals;
use App\Models\Company;
use App\Models\CompanyRenewal;
use App\Models\Computer;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyRenewalsUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 4)->startOfDay());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
    }

    public function test_company_and_computer_views_open_the_same_commercial_register(): void
    {
        $company = $this->company();
        $computer = Computer::create(['company_id' => $company->id, 'name' => 'PC esempio']);
        $this->get('/admin/aziende')->assertOk()->assertSee('Aziende');
        Livewire::test(ManageCompanies::class)->assertCanSeeTableRecords([$company])
            ->mountAction(TestAction::make('companyRenewals')->table($company))->assertSeeLivewire(CompanyRenewals::class);
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction('companyRenewals')->assertSeeLivewire(CompanyRenewals::class);
        Livewire::test(CompanyLicenses::class, ['companyId' => $company->id])
            ->mountAction('companyRenewals')->assertSeeLivewire(CompanyRenewals::class);
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('cycles')->table($computer))->assertSeeLivewire(CompanyRenewals::class);
        Livewire::test(Licenze::class)->set('data.company_id', 'all')->assertActionHidden('companyRenewals');
        Http::assertNothingSent();
    }

    public function test_sale_different_periods_upgrade_and_references_are_editable_without_remote_calls(): void
    {
        $company = $this->company();
        $a = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $b = Computer::create(['company_id' => $company->id, 'name' => 'PC B']);
        $data = $this->operation($a);
        $data['quantity'] = 2;
        $data['periods'][] = ['computer_id' => $b->id, 'starts_on' => '2026-02-01', 'expires_on' => '2027-01-31'];
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id]);
        $page->callAction(TestAction::make('create')->table(), data: $data)->assertHasNoActionErrors();
        $sale = $company->renewals()->sole();
        $this->assertCount(2, $sale->periods);
        $company->forceFill(['technical_product' => 'Premium'])->save();
        $page->callAction(TestAction::make('upgrade')->table(), data: [
            'kind' => 'upgrade', 'occurred_on' => '2026-11-04', 'amount' => '45,50',
        ])->assertHasNoActionErrors();
        $upgrade = $company->renewals()->where('kind', 'upgrade')->sole();
        $this->assertSame(4550, $upgrade->price_cents);
        $this->assertSame('2027-01-31', $b->fresh()->expires_on->toDateString());
        $page->callAction(TestAction::make('edit')->table($upgrade), data: ['invoice_reference' => 'F-2026-10'])
            ->assertHasNoActionErrors();
        $this->assertSame('F-2026-10', $upgrade->fresh()->invoice_reference);
        $details = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])
            ->mountAction(TestAction::make('view')->table($upgrade))->assertActionMounted(TestAction::make('view')->table($upgrade));
        // Read-only modals are rendered as a separate Livewire partial.
        $html = view('filament.commercial-operation', ['operation' => $upgrade->fresh()])->render();
        foreach (['PC A', 'PC B', 'Base', 'Premium'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('edit')->table($a))->assertActionMounted(TestAction::make('edit')->table($a));
        $this->view('filament.computer-cycles', ['computer' => $a->fresh()])->assertSee('Premium')->assertSee('Aggiornamenti aziendali');
        Http::assertNothingSent();
    }

    public function test_future_renewal_does_not_hide_current_expiry_in_filters_or_dashboard_and_notes_remain_editable(): void
    {
        $company = $this->company();
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $data = $this->operation($pc);
        $data['periods'][0]['expires_on'] = '2026-11-20';
        $company->saveCommercialOperation($data, expectedComputerVersions: [$pc->id => 0]);
        $data['kind'] = 'renewal';
        $data['periods'][0] = ['computer_id' => $pc->id, 'starts_on' => '2026-11-21', 'expires_on' => '2027-11-20'];
        $company->saveCommercialOperation($data, expectedComputerVersions: [$pc->id => 1]);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id);
        $page->filterTable('status', 'expiring')->assertCanSeeTableRecords([$pc]);
        $page->callAction(TestAction::make('edit')->table($pc), data: ['notes' => 'Nota conservata'])->assertHasNoActionErrors();
        $this->assertSame('Nota conservata', $pc->fresh()->notes);
        $this->assertSame('2026-11-20', $pc->fresh()->expires_on->toDateString());
        Livewire::test(Dashboard::class)->assertSee('In scadenza');
        Http::assertNothingSent();
    }

    public function test_invalid_reference_and_stale_operation_are_rejected(): void
    {
        $company = $this->company();
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id]);
        $page->callAction(TestAction::make('create')->table(), data: array_replace($this->operation($pc), ['invoice_reference' => str_repeat('A', 256)]))
            ->assertHasActionErrors(['invoice_reference'])->unmountAction();
        $sale = $company->saveCommercialOperation($this->operation($pc), expectedComputerVersions: [$pc->id => 0]);
        $page->mountAction(TestAction::make('edit')->table($sale));
        $company->saveCommercialOperation(array_replace($this->operation($pc), ['amount' => '30']), $sale->id, 0, [$pc->id => 1]);
        $page->setActionData(['amount' => '40'])->callMountedAction()->assertHasActionErrors();
        $this->assertSame(3000, $sale->fresh()->price_cents);
    }

    public function test_legacy_sale_is_linked_in_place_and_existing_sale_accepts_an_additional_computer(): void
    {
        $company = $this->company();
        $a = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $b = Computer::create(['company_id' => $company->id, 'name' => 'PC B']);
        $legacy = CompanyRenewal::forceCreate(['company_id' => $company->id, 'kind' => 'initial', 'starts_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'quantity' => 2]);
        $data = array_replace($this->operation($a), ['quantity' => 2]);
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id]);
        $page->callAction(TestAction::make('link')->table($legacy), data: $data)->assertHasNoActionErrors();
        $this->assertDatabaseCount('company_renewals', 1);
        $this->assertCount(1, $legacy->fresh()->periods);
        $data['periods'][] = ['computer_id' => $b->id, 'starts_on' => '2026-02-01', 'expires_on' => '2027-01-31'];
        $page->callAction(TestAction::make('edit')->table($legacy), data: $data)->assertHasNoActionErrors();
        $this->assertCount(2, $legacy->fresh()->periods);
        $this->assertSame('2027-01-31', $b->fresh()->expires_on->toDateString());
    }

    public function test_multiple_replacements_are_not_shown_as_missing_sales(): void
    {
        $company = $this->company();
        $a = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $b = Computer::create(['company_id' => $company->id, 'name' => 'PC B']);
        $c = Computer::create(['company_id' => $company->id, 'name' => 'PC C']);
        $unlinked = Computer::create(['company_id' => $company->id, 'name' => 'PC da associare']);
        $company->saveCommercialOperation($this->operation($a), expectedComputerVersions: [$a->id => 0]);
        $a->refresh()->transferTo($b, $a->commercial_version);
        $b->transferTo($c, $b->commercial_version);

        $this->view('filament.computer-cycles', ['computer' => $b->fresh()])
            ->assertSee('Licenza trasferita')->assertDontSee('Da associare a una vendita');
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->filterTable('unlinked')->assertCanSeeTableRecords([$unlinked])
            ->assertCanNotSeeTableRecords([$a, $b, $c]);
        $this->assertSame([$unlinked->id], Computer::query()->unlinked()->pluck('id')->all());
        Http::assertNothingSent();
    }

    public function test_guests_disabled_users_and_foreign_records_are_rejected(): void
    {
        $company = $this->company();
        $other = $this->company();
        $pc = Computer::create(['company_id' => $other->id, 'name' => 'PC esterno']);
        $sale = $other->saveCommercialOperation($this->operation($pc), expectedComputerVersions: [$pc->id => 0]);
        Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])->assertActionDoesNotExist(TestAction::make('edit')->table($sale));
        Livewire::test(CompanyRenewals::class, ['companyId' => $company->id, 'computerId' => $pc->id])->assertNotFound();
        auth()->logout();
        Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])->assertForbidden();
        $user = User::factory()->create();
        $this->actingAs($user);
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])->mountAction(TestAction::make('create')->table());
        $user->update(['is_active' => false]);
        $page->callMountedAction()->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_product_is_never_requested_when_creating_or_correcting_operations(): void
    {
        $company = $this->company();
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $sale = $company->saveCommercialOperation($this->operation($pc), expectedComputerVersions: [$pc->id => 0]);
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id]);
        foreach ([TestAction::make('create')->table(), TestAction::make('upgrade')->table(), TestAction::make('edit')->table($sale)] as $action) {
            $page->mountAction($action)->assertFormFieldDoesNotExist('product')->assertFormFieldDoesNotExist('billing_status')->unmountAction();
        }
        $this->assertSame('Base', $sale->periods->sole()->product);
        Http::assertNothingSent();
    }

    public function test_old_billing_states_are_not_exposed_or_required_by_license_workflows(): void
    {
        $company = $this->company();
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $sale = $company->saveCommercialOperation($this->operation($pc), expectedComputerVersions: [$pc->id => 0]);
        $sale->forceFill(['billing_status' => 'pending'])->save();

        Livewire::test(ManageCompanies::class)->assertDontSee('Da fatturare');
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->assertDontSee('Da fatturare')->assertSee('Ciclo licenza');
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])
            ->assertDontSee('Da fatturare')->assertDontSee('Fatturazione');
        $page->callAction(TestAction::make('edit')->table($sale), data: ['amount' => '30', 'invoice_reference' => null])
            ->assertHasNoActionErrors();
        $this->assertSame(3000, $sale->fresh()->price_cents);
        $this->assertNull($sale->fresh()->invoice_reference);
        $this->assertSame('pending', $sale->fresh()->billing_status);
        $this->view('filament.commercial-operation', ['operation' => $sale->fresh()])
            ->assertDontSee('Da fatturare')->assertDontSee('Fatturazione');
        $this->view('filament.computer-cycles', ['computer' => $pc->fresh()])
            ->assertDontSee('Da fatturare')->assertDontSee('Fatturazione');
        Http::assertNothingSent();
    }

    private function company(): Company
    {
        return Company::forceCreate(['name' => 'Azienda '.Company::count(), 'gravityzone_id' => 'company-'.Company::count(), 'last_seen_at' => now(), 'technical_product' => 'Base']);
    }

    private function operation(Computer $pc): array
    {
        return [
            'kind' => 'initial', 'occurred_on' => '2026-01-01', 'quantity' => 1,
            'periods' => [['computer_id' => $pc->id, 'starts_on' => '2026-01-01', 'expires_on' => '2026-12-31']],
        ];
    }
}
