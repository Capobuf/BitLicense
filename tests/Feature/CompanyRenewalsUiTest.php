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

    public function test_common_dates_validate_before_updating_rows_and_allow_individual_changes(): void
    {
        $company = $this->company();
        $a = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $b = Computer::create(['company_id' => $company->id, 'name' => 'PC B']);
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])
            ->mountAction(TestAction::make('create')->table())
            ->set('mountedActions.0.data.computer_ids', [$a->id, $b->id]);
        $before = $page->get('mountedActions.0.data.periods');
        $page->mountAction(TestAction::make('applyDates')->schemaComponent('periods', 'mountedActionSchema0'))
            ->setActionData(['starts_on' => '2026-11-04', 'expires_on' => '2026-11-03'])
            ->callMountedAction()->assertHasActionErrors(['expires_on']);
        $this->assertSame($before, $page->get('mountedActions.0.data.periods'));
        $page->setActionData(['starts_on' => '2026-11-04', 'expires_on' => '2027-11-03'])
            ->callMountedAction()->assertHasNoActionErrors();
        $rows = $page->get('mountedActions.0.data.periods');
        $this->assertSame([$a->id, $b->id], array_column($rows, 'computer_id'));
        $this->assertSame(['2026-11-04', '2026-11-04'], array_column($rows, 'starts_on'));
        $this->assertSame(['2027-11-03', '2027-11-03'], array_column($rows, 'expires_on'));
        $this->assertDatabaseCount('company_renewals', 0);
        $rows[array_key_last($rows)]['expires_on'] = '2027-12-31';
        $page->setActionData(['periods' => $rows])->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('2027-11-03', $a->fresh()->expires_on->toDateString());
        $this->assertSame('2027-12-31', $b->fresh()->expires_on->toDateString());
        Http::assertNothingSent();
    }

    public function test_switching_to_renewal_refreshes_suggestions_and_preserves_custom_dates(): void
    {
        $company = $this->company();
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $company->saveCommercialOperation($this->operation($pc), expectedComputerVersions: [$pc->id => 0]);
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])
            ->mountAction(TestAction::make('create')->table())
            ->set('mountedActions.0.data.computer_ids', [$pc->id])
            ->set('mountedActions.0.data.kind', 'renewal');
        $rows = $page->get('mountedActions.0.data.periods');
        $this->assertSame('2027-01-01', reset($rows)['starts_on']);
        $this->assertNull(reset($rows)['expires_on']);
        $rows[array_key_first($rows)]['starts_on'] = '2027-02-01';
        $rows[array_key_first($rows)]['expires_on'] = '2028-01-31';
        $page->setActionData(['periods' => $rows])
            ->set('mountedActions.0.data.kind', 'initial')
            ->set('mountedActions.0.data.kind', 'renewal');
        $this->assertSame($rows, $page->get('mountedActions.0.data.periods'));
        $page->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('2027-02-01', $company->renewals()->where('kind', 'renewal')->sole()->periods->sole()->starts_on->toDateString());
        Http::assertNothingSent();
    }

    public function test_computer_actions_preselect_the_pc_and_keep_sales_and_renewals_distinct(): void
    {
        $company = $this->company();
        $a = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $b = Computer::create(['company_id' => $company->id, 'name' => 'PC B']);
        $sale = $company->saveCommercialOperation($this->operation($a), expectedComputerVersions: [$a->id => 0, $b->id => 0]);
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id, 'computerId' => $a->id, 'computerContext' => true])
            ->assertSee('Modifica vendita V1')->assertSee('Cambia vendita')->assertSee('Associa a rinnovo');
        $page->mountAction(TestAction::make('edit')->table($sale))->assertActionDataSet(['computer_ids' => [$a->id]])->unmountAction();
        $page->mountAction(TestAction::make('moveComputers')->table($sale))->assertActionDataSet(['computer_ids' => [$a->id]])->unmountAction();
        $renewal = $company->saveCommercialOperation(['kind' => 'renewal', 'occurred_on' => '2026-11-04', 'quantity' => 8,
            'starts_on' => '2027-01-01', 'expires_on' => '2027-12-31', 'periods' => []]);
        $page->mountAction(TestAction::make('associateRenewal')->table())
            ->set('mountedActions.0.data.renewal_id', $renewal->id)
            ->assertActionDataSet(['starts_on' => '2027-01-01', 'expires_on' => '2027-12-31'])
            ->callMountedAction()->assertHasNoActionErrors()->assertSee('Prossimo periodo');
        $this->assertSame(8, $renewal->fresh()->quantity);
        $this->assertSame($sale->periods->sole()->id, $renewal->fresh()->periods->sole()->previous_period_id);
        $this->assertSame('2026-12-31', $a->fresh()->expires_on->toDateString());
        $page->mountAction(TestAction::make('newSale')->table())->assertActionDataSet(['kind' => 'initial', 'computer_ids' => []])->unmountAction();
        Livewire::test(CompanyRenewals::class, ['companyId' => $company->id, 'computerId' => $b->id, 'computerContext' => true])
            ->mountAction(TestAction::make('newSale')->table())->assertActionDataSet(['kind' => 'initial', 'computer_ids' => [$b->id]])
            ->setActionData($this->operation($b))->callMountedAction()->assertHasNoActionErrors()->assertSee('Modifica vendita V2');
        Http::assertNothingSent();
    }

    public function test_computer_notes_can_be_saved_after_correcting_a_sale_but_concurrent_notes_are_protected(): void
    {
        $company = $this->company();
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $sale = $company->saveCommercialOperation($this->operation($pc), expectedComputerVersions: [$pc->id => 0]);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('edit')->table($pc))->assertSeeLivewire(CompanyRenewals::class)
            ->setActionData(['notes' => 'Bozza conservata']);
        $company->saveCommercialOperation(array_replace($this->operation($pc), ['amount' => '45']), $sale->id, 0, [$pc->id => 1]);
        $page->dispatch('company-renewals-updated')->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('Bozza conservata', $pc->fresh()->notes);

        $page->mountAction(TestAction::make('edit')->table($pc))->setActionData(['notes' => 'Bozza successiva']);
        $pc->refresh()->updateCommercial(['notes' => 'Modifica di un altro operatore'], $pc->commercial_version);
        $page->dispatch('company-renewals-updated')->callMountedAction()->assertHasActionErrors();
        $this->assertSame('Modifica di un altro operatore', $pc->fresh()->notes);
        Http::assertNothingSent();
    }

    public function test_unlinked_computer_keeps_note_draft_after_its_first_sale_is_registered(): void
    {
        $company = $this->company();
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('edit')->table($pc))->setActionData(['notes' => 'Nota in bozza']);
        $company->saveCommercialOperation($this->operation($pc), expectedComputerVersions: [$pc->id => 0]);
        $page->dispatch('company-renewals-updated')->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('Nota in bozza', $pc->fresh()->notes);
        $this->assertSame('2026-12-31', $pc->fresh()->expires_on->toDateString());
    }

    public function test_company_can_register_empty_renewal_and_pc_selection_never_increases_quantity(): void
    {
        $company = $this->company();
        $pcs = collect(range(1, 3))->map(fn ($number) => Computer::create(['company_id' => $company->id, 'name' => 'PC '.$number]));
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id]);
        $page->mountAction(TestAction::make('create')->table())
            ->set('mountedActions.0.data.kind', 'renewal')
            ->setActionData(['quantity' => 2, 'starts_on' => '2027-01-01', 'expires_on' => '2027-12-31'])
            ->callMountedAction()->assertHasNoActionErrors();
        $renewal = $company->renewals()->sole();
        $this->assertCount(0, $renewal->periods);
        $this->assertSame('2027-12-31', $renewal->expires_on->toDateString());
        $this->view('filament.commercial-operation', ['operation' => $renewal])->assertSee('Licenze disponibili')->assertSee('Nessun computer associato');

        $page->mountAction(TestAction::make('edit')->table($renewal))
            ->set('mountedActions.0.data.computer_ids', $pcs->pluck('id')->all())
            ->assertActionDataSet(['quantity' => 2])->callMountedAction()->assertHasActionErrors(['quantity']);
        $this->assertCount(0, $renewal->fresh()->periods);
        $page->set('mountedActions.0.data.computer_ids', $pcs->take(2)->pluck('id')->all())
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertCount(2, $renewal->fresh()->periods);
        $this->assertSame('2027-01-01', $renewal->fresh()->periods->first()->starts_on->toDateString());
        $this->assertSame(2, $renewal->fresh()->quantity);
        $this->assertNull($pcs->last()->fresh()->expires_on);
        Http::assertNothingSent();
    }

    public function test_association_rejects_stale_full_foreign_and_duplicate_renewals(): void
    {
        $company = $this->company();
        $a = Computer::create(['company_id' => $company->id, 'name' => 'PC A']);
        $b = Computer::create(['company_id' => $company->id, 'name' => 'PC B']);
        $data = ['kind' => 'renewal', 'occurred_on' => '2026-11-04', 'quantity' => 1, 'starts_on' => '2027-01-01', 'expires_on' => '2027-12-31', 'periods' => []];
        $renewal = $company->saveCommercialOperation($data);
        $foreign = $this->company()->saveCommercialOperation($data);
        $page = Livewire::test(CompanyRenewals::class, ['companyId' => $company->id, 'computerId' => $a->id, 'computerContext' => true])
            ->assertSee('Associa a rinnovo')->mountAction(TestAction::make('associateRenewal')->table())
            ->set('mountedActions.0.data.renewal_id', $renewal->id);
        $company->saveCommercialOperation(array_replace($data, ['amount' => '80']), $renewal->id, 0);
        $page->callMountedAction()->assertHasActionErrors()->unmountAction();
        $this->assertCount(0, $renewal->fresh()->periods);
        $page->mountAction(TestAction::make('associateRenewal')->table())
            ->set('mountedActions.0.data.renewal_id', $foreign->id)->callMountedAction()->assertHasActionErrors(['renewal_id'])->unmountAction();
        $page->mountAction(TestAction::make('associateRenewal')->table())
            ->set('mountedActions.0.data.renewal_id', $renewal->id)->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(1, $renewal->fresh()->quantity);
        $this->assertSame(8000, $renewal->fresh()->price_cents);
        foreach ([$a, $b] as $pc) {
            Livewire::test(CompanyRenewals::class, ['companyId' => $company->id, 'computerId' => $pc->id, 'computerContext' => true])
                ->mountAction(TestAction::make('associateRenewal')->table())
                ->set('mountedActions.0.data.renewal_id', $renewal->id)->callMountedAction()->assertHasActionErrors(['renewal_id']);
        }
        $this->assertCount(1, $renewal->fresh()->periods);
        $this->assertCount(0, $foreign->fresh()->periods);
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
