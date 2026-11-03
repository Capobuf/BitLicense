<?php

namespace Tests\Feature;

use App\Filament\Pages\Licenze;
use App\Integrations\GravityZone\CompanySynchronizer;
use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\Company;
use App\Models\Computer;
use App\Models\GravityZoneSetting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class LicenzeTest extends TestCase
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

    public function test_one_operational_page_scopes_computers_and_keeps_the_company_expiry_in_the_same_table(): void
    {
        $company = $this->company();
        $pc = $this->computer($company, ['activated_on' => '2026-05-01', 'expires_on' => '2027-05-01']);
        $other = $this->computer($this->company());
        $page = Livewire::test(Licenze::class)->assertCountTableRecords(0)->assertActionDisabled('create');
        $page->set('data.company_id', $company->id)->assertCanSeeTableRecords([$pc])->assertCanNotSeeTableRecords([$other])
            ->assertSee('31/12/2026')->assertSee('01/05/2027')->assertSee('Scadenza tecnica')->assertSee('Scadenza commerciale');
        $this->get('/admin')->assertOk()->assertSee('Licenze')->assertDontSee('Coperture commerciali')->assertDontSee('Panoramica');
        foreach (['aziende', 'endpoint', 'postazioni', 'coperture'] as $path) {
            $this->get('/admin/'.$path)->assertNotFound();
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_click_edit_completes_an_imported_pc_without_optional_fields_or_changing_remote_values(): void
    {
        $company = $this->company();
        $pc = $this->computer($company, ['gravityzone_id' => 'remote-1', 'gravityzone_company_id' => $company->id, 'technical_name' => 'PC-GZ', 'name' => null]);
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->assertSee('Da completare')
            ->mountAction(TestAction::make('edit')->table($pc))->fillForm(['activated_on' => '2026-05-01', 'expires_on' => '2027-05-01'])
            ->set('mountedActions.0.data.technical_name', 'MANOMESSO')->set('mountedActions.0.data.company_id', 9999)
            ->callMountedAction()->assertHasNoFormErrors()->assertNotified('Dati salvati');
        $this->assertSame('2027-05-01', $pc->refresh()->expires_on->toDateString());
        $this->assertSame('PC-GZ', $pc->technical_name);
        $this->assertSame($company->id, $pc->company_id);
        $this->assertNull($pc->price_cents);
        $this->assertSame('2026-12-31', $company->refresh()->technical_expires_on->toDateString());
        $this->assertDatabaseCount('computers', 1);
    }

    public function test_manual_pc_is_created_and_edited_in_the_selected_company_with_optional_exact_price(): void
    {
        $company = $this->company();
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id);
        $page->callAction('create', data: ['name' => 'PC manuale'])->assertHasNoFormErrors()->assertNotified('Dati salvati');
        $pc = Computer::sole();
        $this->assertNull($pc->gravityzone_id);
        $page->callAction(TestAction::make('edit')->table($pc), data: [
            'name' => 'PC ufficio', 'product' => 'Suite con add-on', 'activated_on' => '2026-04-20', 'expires_on' => '2027-04-20',
            'amount' => '79,90', 'commercial_description' => 'Descrizione', 'invoice_reference' => 'FT-12', 'quote_reference' => 'PR-14', 'notes' => 'Note locali',
        ])->assertHasNoFormErrors();
        $this->assertSame($company->id, $pc->refresh()->company_id);
        $this->assertSame(7990, $pc->price_cents);
        $this->assertSame('Note locali', $pc->notes);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_annual_suggestion_handles_leap_day_and_keeps_an_operator_chosen_expiry(): void
    {
        $company = $this->company();
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->mountAction('create')
            ->set('mountedActions.0.data.activated_on', '2028-02-29')->assertActionDataSet(['expires_on' => '2029-02-28'])
            ->set('mountedActions.0.data.expires_on', '2030-12-31')->set('mountedActions.0.data.activated_on', '2028-03-01')
            ->assertActionDataSet(['expires_on' => '2030-12-31']);
    }

    public function test_invalid_dates_and_amounts_fail_atomically_and_show_field_errors(): void
    {
        $company = $this->company();
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->callAction('create', data: [
            'name' => 'Non salvare', 'activated_on' => '2026-05-01', 'expires_on' => '2026-04-30',
        ])->assertHasFormErrors(['expires_on']);
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->callAction('create', data: [
            'name' => 'Non salvare', 'amount' => '12.345',
        ])->assertHasFormErrors(['amount']);
        $this->assertDatabaseCount('computers', 0);
    }

    public function test_contextual_reconciliation_and_transfer_preserve_dates_and_visible_history(): void
    {
        $company = $this->company();
        $manual = $this->computer($company, ['activated_on' => '2026-04-20', 'expires_on' => '2027-04-20', 'price_cents' => 7990]);
        $imported = $this->computer($company, ['name' => null, 'technical_name' => 'PC-GZ', 'gravityzone_id' => 'id-verificato', 'gravityzone_company_id' => $company->id]);
        $replacement = $this->computer($company, ['name' => 'Sostitutivo']);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id);
        $page->callAction(TestAction::make('reconcile')->table($manual), data: ['target_id' => $imported->id])->assertHasNoFormErrors();
        $this->assertDatabaseCount('computers', 2);
        $page->callAction(TestAction::make('transfer')->table($manual), data: ['target_id' => $replacement->id])->assertHasNoFormErrors();
        $this->assertNull($manual->refresh()->expires_on);
        $this->assertSame('2027-04-20', $replacement->refresh()->expires_on->toDateString());
        $this->assertSame(7990, $replacement->price_cents);
        $page->mountAction(TestAction::make('edit')->table($manual));
        $html = $page->instance()->mountedActionSchema0->toHtml();
        $this->assertStringContainsString('Sostitutivo', $html);
        $this->assertStringContainsString('20/04/2027', $html);
        Http::assertNothingSent();
    }

    public function test_stale_forms_and_foreign_or_occupied_transfer_targets_do_not_overwrite_data(): void
    {
        $company = $this->company();
        $pc = $this->computer($company, ['activated_on' => '2026-04-20', 'expires_on' => '2027-04-20']);
        $foreign = $this->computer($this->company());
        $occupied = $this->computer($company, ['product' => 'Già compilato']);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)->mountAction(TestAction::make('edit')->table($pc));
        $pc->updateCommercial(['notes' => 'Aggiornata da altro operatore'], 0);
        $page->fillForm(['notes' => 'Non salvare'])->callMountedAction()->assertHasFormErrors(['activated_on']);
        $this->assertSame('Aggiornata da altro operatore', $pc->refresh()->notes);
        foreach ([$foreign, $occupied] as $target) {
            Livewire::test(Licenze::class)->set('data.company_id', $company->id)
                ->callAction(TestAction::make('transfer')->table($pc), data: ['target_id' => $target->id])->assertHasFormErrors(['target_id']);
        }
        $this->assertNotNull($pc->refresh()->expires_on);
    }

    public function test_status_filter_handles_missing_dates_today_future_and_thirty_day_boundary(): void
    {
        $company = $this->company();
        $missing = $this->computer($company);
        $expired = $this->computer($company, ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-02']);
        $today = $this->computer($company, ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-03']);
        $boundary = $this->computer($company, ['activated_on' => '2026-01-01', 'expires_on' => '2026-12-03']);
        $active = $this->computer($company, ['activated_on' => '2026-01-01', 'expires_on' => '2026-12-04']);
        $future = $this->computer($company, ['activated_on' => '2026-12-01', 'expires_on' => '2027-12-01']);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id);
        foreach (['missing' => [$missing], 'expired' => [$expired], 'expiring' => [$today, $boundary], 'active' => [$active], 'future' => [$future]] as $filter => $pcs) {
            $page->filterTable('status', $filter)->assertCanSeeTableRecords($pcs)->assertCountTableRecords(count($pcs));
        }
        $page->resetTableFilters()->searchTable($active->name)->assertCountTableRecords(1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_missing_stale_and_cross_company_remote_data_remain_visible_without_secrets(): void
    {
        $company = $this->company();
        $company->forceFill(['technical_expires_on' => null, 'is_present' => false, 'endpoints_sync_error' => 'Lettura PC fallita.'])->save();
        $other = $this->company();
        $pc = $this->computer($company, ['gravityzone_id' => 'moved', 'gravityzone_company_id' => $other->id, 'is_present' => false]);
        GravityZoneSetting::create(['id' => 1, 'api_url' => 'https://example.test/api', 'api_key' => 'never-render-key'])
            ->forceFill(['endpoints_sync_error' => 'Import incompleto.'])->save();
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->assertCanSeeTableRecords([$pc])
            ->assertSee('Non disponibile')->assertSee('Azienda non più rilevata')->assertSee('Lettura PC fallita.')
            ->assertSee('Da verificare: rilevato in')->assertSee('Import incompleto.')->assertDontSee('never-render-key');
        Http::assertNothingSent();
    }

    public function test_sync_requires_admin_rechecks_role_and_reports_failures_without_success(): void
    {
        Livewire::test(Licenze::class)->assertActionHidden('synchronize')->call('mountAction', 'synchronize')->assertActionNotMounted();
        $user = User::factory()->administrator()->create();
        $this->actingAs($user);
        $this->mock(CompanySynchronizer::class)->shouldReceive('sync')->once()->andReturn(1);
        $this->mock(EndpointSynchronizer::class)->shouldReceive('sync')->once()->andReturn(2);
        Livewire::test(Licenze::class)->callAction('synchronize')->assertNotified('Sincronizzazione completata');
        $this->mock(CompanySynchronizer::class)->shouldReceive('sync')->once()->andThrow(new GravityZoneException('Errore lettura.'));
        $this->mock(EndpointSynchronizer::class)->shouldNotReceive('sync');
        Livewire::test(Licenze::class)->callAction('synchronize')->assertNotified('Sincronizzazione non completata');
        $this->mock(CompanySynchronizer::class)->shouldNotReceive('sync');
        $page = Livewire::test(Licenze::class)->mountAction('synchronize');
        $user->update(['role' => 'operator']);
        $this->actingAs($user->fresh());
        $page->callMountedAction()->assertStatus(200);
        Http::assertNothingSent();
    }

    public function test_disabling_user_blocks_an_already_open_edit_and_direct_access(): void
    {
        $company = $this->company();
        $pc = $this->computer($company);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)->mountAction(TestAction::make('edit')->table($pc));
        $user = auth()->user();
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh());
        $page->callMountedAction()->assertForbidden();
        $this->get('/admin')->assertForbidden();
        $this->assertNull($pc->refresh()->expires_on);
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-'.Company::count(), 'name' => 'Azienda '.Company::count(), 'last_seen_at' => now(), 'technical_expires_on' => '2026-12-31'])->save();

        return $company;
    }

    private function computer(Company $company, array $data = []): Computer
    {
        $pc = new Computer;
        $pc->forceFill(array_replace(['company_id' => $company->id, 'name' => 'PC '.Computer::count(), 'is_present' => true], $data))->save();

        return $pc->refresh();
    }
}
