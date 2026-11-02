<?php

namespace Tests\Feature;

use App\Filament\Resources\Coverages\Pages\ManageCoverages;
use App\Models\Company;
use App\Models\Coverage;
use App\Models\User;
use App\Models\Workstation;
use App\Services\CoverageService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ViewErrorBag;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class CoverageManagementTest extends TestCase
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

    public function test_four_workstations_and_a_fifth_keep_their_own_commercial_periods_under_one_technical_expiry(): void
    {
        $company = $this->company();
        $workstations = collect(range(1, 5))->map(fn (int $number): Workstation => Workstation::query()->create(['company_id' => $company->id, 'name' => 'PC '.$number]));
        $this->get('/admin/coperture')->assertOk();
        Livewire::test(ManageCoverages::class)->mountAction('create')
            ->assertActionDataSet(['starts_on' => null, 'ends_on' => null])->unmountAction()
            ->callAction('create', data: [
                ...$this->input(), 'company_id' => $company->id, 'workstation_ids' => $workstations->take(4)->pluck('id')->all(),
            ])->assertHasNoFormErrors()->assertNotified('Coperture create');
        Livewire::test(ManageCoverages::class)->callAction('create', data: [
            ...$this->input(), 'company_id' => $company->id, 'workstation_ids' => [$workstations->last()->id],
            'starts_on' => '2026-05-01', 'ends_on' => '2027-05-01', 'amount' => '85.99',
        ])->assertHasNoFormErrors();

        $this->assertDatabaseCount('coverages', 5);
        $this->assertSame(4, Coverage::query()->whereDate('starts_on', '2026-04-20')->whereDate('ends_on', '2027-04-20')->where('price_cents', 10050)->count());
        $fifth = Coverage::query()->where('workstation_id', $workstations->last()->id)->sole();
        $this->assertSame('2026-05-01', $fifth->starts_on->toDateString());
        $this->assertSame('2027-05-01', $fifth->ends_on->toDateString());
        $this->assertSame(8599, $fifth->price_cents);
        $this->assertSame('2026-12-31', $company->refresh()->technical_expires_on->toDateString());
        $coverage = Coverage::query()->firstOrFail();
        $page = Livewire::test(ManageCoverages::class)->mountAction(TestAction::make('view')->table($coverage));
        $details = $page->instance()->mountedActionSchema0->toHtml();
        $this->assertStringContainsString('Scadenza tecnica aziendale', $details);
        $this->assertStringContainsString('31/12/2026', $details);
        $this->assertStringContainsString('20/04/2027', $details);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_correction_changes_period_price_and_references_but_ignores_company_and_workstation_tampering(): void
    {
        $coverage = $this->coverage($this->workstation($this->company()));
        $other = $this->workstation($this->company());
        Livewire::test(ManageCoverages::class)->mountAction(TestAction::make('edit')->table($coverage))
            ->assertActionDataSet(['amount' => '100.50'])
            ->fillForm(['starts_on' => '2026-04-21', 'ends_on' => '2027-05-15', 'amount' => '109,90', 'invoice_reference' => 'FT 18', 'quote_reference' => 'PR 27'])
            ->set('mountedActions.0.data.company_id', $other->company_id)
            ->set('mountedActions.0.data.workstation_id', $other->id)
            ->set('mountedActions.0.data.price_cents', 1)
            ->callMountedAction()->assertHasNoFormErrors()->assertNotified('Copertura aggiornata');
        $originalWorkstation = $coverage->workstation_id;
        $originalCompany = $coverage->company_id;
        $coverage->refresh();
        $this->assertSame($originalWorkstation, $coverage->workstation_id);
        $this->assertSame($originalCompany, $coverage->company_id);
        $this->assertSame(10990, $coverage->price_cents);
        $this->assertSame('2027-05-15', $coverage->ends_on->toDateString());
        $this->assertSame('FT 18', $coverage->invoice_reference);
        $this->assertSame('PR 27', $coverage->quote_reference);
        $this->assertSame(1, $coverage->assignments()->count());
    }

    public function test_transfer_preserves_the_purchased_period_price_references_and_assignment_history(): void
    {
        $company = $this->company();
        $source = $this->workstation($company);
        $target = $this->workstation($company);
        $coverage = $this->coverage($source);
        $before = $coverage->only(['company_id', 'starts_on', 'ends_on', 'price_cents', 'invoice_reference', 'quote_reference']);
        Livewire::test(ManageCoverages::class)->callAction(TestAction::make('transfer')->table($coverage), data: ['target_workstation_id' => $target->id])
            ->assertHasNoFormErrors()->assertNotified('Copertura trasferita');
        $coverage->refresh();
        $this->assertSame($target->id, $coverage->workstation_id);
        $this->assertEquals($before, $coverage->only(array_keys($before)));
        $this->assertSame(2, $coverage->assignments()->count());
        $this->assertNotNull($coverage->assignments()->where('workstation_id', $source->id)->sole()->released_at);
        $this->assertNull($coverage->assignments()->where('workstation_id', $target->id)->sole()->released_at);
        $page = Livewire::test(ManageCoverages::class)->mountAction(TestAction::make('view')->table($coverage));
        $history = $page->instance()->mountedActionSchema0->toHtml();
        $this->assertStringContainsString('#'.$source->id, $history);
        $this->assertStringContainsString('#'.$target->id, $history);
        $this->assertStringContainsString('Assegnazione attuale', $history);
    }

    public function test_foreign_company_selection_and_overlap_are_presented_as_visible_form_errors(): void
    {
        $company = $this->company();
        $source = $this->workstation($company);
        $occupied = $this->workstation($company);
        $foreign = $this->workstation($this->company());
        $coverage = $this->coverage($source);
        $this->coverage($occupied);

        $invalid = Livewire::test(ManageCoverages::class)->callAction('create', data: [
            ...$this->input(), 'company_id' => $company->id, 'workstation_ids' => [$foreign->id],
        ]);
        $invalid->assertHasFormErrors(['workstation_ids.0']);
        Livewire::test(ManageCoverages::class)->callAction(TestAction::make('transfer')->table($coverage), data: ['target_workstation_id' => $foreign->id])
            ->assertHasFormErrors(['target_workstation_id']);
        $page = Livewire::test(ManageCoverages::class)->callAction(TestAction::make('transfer')->table($coverage), data: ['target_workstation_id' => $occupied->id])
            ->assertHasFormErrors(['target_workstation_id']);
        view()->share('errors', (new ViewErrorBag)->put('default', $page->instance()->getErrorBag()));
        $message = $page->errors()->first('mountedActions.0.data.target_workstation_id');
        $this->assertStringContainsString('sovrapposta', $message);
        $this->assertStringContainsString(e($message), $page->instance()->mountedActionSchema0->toHtml());
        $this->assertSame($source->id, $coverage->refresh()->workstation_id);
        $this->assertSame(1, $coverage->assignments()->count());
    }

    public function test_open_edit_and_transfer_forms_reject_a_concurrent_transfer_instead_of_overwriting_it(): void
    {
        $company = $this->company();
        $source = $this->workstation($company);
        $destination = $this->workstation($company);
        $third = $this->workstation($company);
        $coverage = $this->coverage($source);
        $edit = Livewire::test(ManageCoverages::class)->mountAction(TestAction::make('edit')->table($coverage))->fillForm(['amount' => '999']);
        $transfer = Livewire::test(ManageCoverages::class)->mountAction(TestAction::make('transfer')->table($coverage))->fillForm(['target_workstation_id' => $third->id]);
        app(CoverageService::class)->transfer($coverage, $destination);

        $edit->callMountedAction()->assertHasFormErrors(['starts_on']);
        $transfer->callMountedAction()->assertHasFormErrors(['target_workstation_id']);
        $this->assertSame($destination->id, $coverage->refresh()->workstation_id);
        $this->assertSame(10050, $coverage->price_cents);
        $this->assertSame(2, $coverage->assignments()->count());
    }

    public function test_the_original_assignment_snapshot_cannot_be_changed_by_the_browser(): void
    {
        $coverage = $this->coverage($this->workstation($this->company()));
        $page = Livewire::test(ManageCoverages::class)->mountAction(TestAction::make('transfer')->table($coverage));
        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('expectedAssignments', [$coverage->id => 999]);
    }

    public function test_a_missing_record_during_creation_reports_failure_without_success_or_partial_local_data(): void
    {
        $local = $this->workstation($this->company());
        $this->mock(CoverageService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('create')->once()->andThrow(new ModelNotFoundException);
        });
        Livewire::test(ManageCoverages::class)->callAction('create', data: [
            ...$this->input(), 'company_id' => $local->company_id, 'workstation_ids' => [$local->id],
        ])->assertNotified('Operazione non completata');
        $this->assertDatabaseCount('coverages', 0);
    }

    public function test_no_delete_and_disabled_users_cannot_create_edit_or_transfer(): void
    {
        $coverage = $this->coverage($this->workstation($this->company()));
        $page = Livewire::test(ManageCoverages::class)->assertActionDoesNotExist(TestAction::make('delete')->table($coverage))
            ->mountAction(TestAction::make('edit')->table($coverage))->fillForm(['amount' => '999']);
        $user = auth()->user();
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh());
        $page->callMountedAction()->assertForbidden();
        $this->get('/admin/coperture')->assertForbidden();
        Livewire::test(ManageCoverages::class)->assertForbidden();
        $this->assertFalse(Gate::allows('create', Coverage::class));
        $this->assertFalse(Gate::allows('transfer', $coverage));
        $this->assertFalse(Gate::allows('delete', $coverage));
        $this->assertSame(10050, $coverage->refresh()->price_cents);
    }

    private function input(): array
    {
        return ['starts_on' => '2026-04-20', 'ends_on' => '2027-04-20', 'amount' => '100,50', 'invoice_reference' => 'FT 1', 'quote_reference' => 'PR 2'];
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-'.Company::query()->count(), 'name' => 'Azienda test', 'last_seen_at' => now(), 'is_present' => true, 'technical_expires_on' => '2026-12-31'])->save();

        return $company;
    }

    private function workstation(Company $company): Workstation
    {
        return Workstation::query()->create(['company_id' => $company->id, 'name' => 'PC locale '.Workstation::query()->count()]);
    }

    private function coverage(Workstation $workstation): Coverage
    {
        return app(CoverageService::class)->create([...$this->input(), 'company_id' => $workstation->company_id, 'workstation_ids' => [$workstation->id]])->sole();
    }
}
