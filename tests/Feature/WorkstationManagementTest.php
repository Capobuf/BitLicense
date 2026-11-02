<?php

namespace Tests\Feature;

use App\Filament\Resources\Workstations\Pages\ManageWorkstations;
use App\Models\Company;
use App\Models\Endpoint;
use App\Models\User;
use App\Models\Workstation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class WorkstationManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
    }

    public function test_an_operator_creates_a_manual_workstation_without_automatic_endpoint_association(): void
    {
        $this->actingAs(User::factory()->create());
        $company = $this->company();
        $this->get('/admin/postazioni')->assertOk();
        Livewire::test(ManageWorkstations::class)->mountAction('create')
            ->fillForm(['company_id' => $company->id, 'name' => 'PC manuale', 'notes' => 'Note locali'])
            ->set('mountedActions.0.data.endpoint_id', 123)->callMountedAction()->assertHasNoFormErrors();
        $local = Workstation::query()->sole();
        $this->assertSame($company->id, $local->company_id);
        $this->assertSame('PC manuale', $local->name);
        $this->assertSame('Note locali', $local->notes);
        $this->assertNull($local->endpoint_id);
        Http::assertNothingSent();
    }

    public function test_editing_cannot_change_company_or_endpoint_and_keeps_the_remote_name_separate(): void
    {
        $this->actingAs(User::factory()->create());
        $company = $this->company();
        $endpoint = new Endpoint;
        $endpoint->forceFill(['company_id' => $company->id, 'gravityzone_id' => 'endpoint-test', 'name' => 'Nome tecnico', 'is_present' => true, 'last_seen_at' => now()])->save();
        $local = Workstation::query()->create(['company_id' => $company->id, 'name' => 'Nome locale']);
        $local->linkEndpoint($endpoint);
        Livewire::test(ManageWorkstations::class)->mountAction(TestAction::make('edit')->table($local))
            ->fillForm(['name' => 'Nome commerciale aggiornato', 'notes' => 'Note nuove'])
            ->set('mountedActions.0.data.company_id', $this->company()->id)
            ->set('mountedActions.0.data.endpoint_id', null)->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame($company->id, $local->refresh()->company_id);
        $this->assertSame($endpoint->id, $local->endpoint_id);
        $this->assertSame('Nome commerciale aggiornato', $local->name);
        $this->assertSame('Note nuove', $local->notes);
        $this->assertSame('Nome tecnico', $endpoint->refresh()->name);
        Livewire::test(ManageWorkstations::class)->assertSee('Nome commerciale aggiornato')->assertSee('Nome tecnico')
            ->assertActionDoesNotExist(TestAction::make('delete')->table($local));
        $this->assertFalse(Gate::allows('delete', $local));
    }

    public function test_name_and_existing_company_are_required(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(ManageWorkstations::class)->callAction('create', data: ['name' => '', 'company_id' => 999])
            ->assertHasFormErrors(['name' => 'required', 'company_id']);
        $this->assertDatabaseCount('workstations', 0);
    }

    public function test_a_disabled_operator_cannot_access_or_save_an_open_manual_workstation(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $local = Workstation::query()->create(['company_id' => $this->company()->id, 'name' => 'Nome iniziale']);
        $page = Livewire::test(ManageWorkstations::class)->mountAction(TestAction::make('edit')->table($local))->fillForm(['name' => 'Non salvare']);
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh());
        $page->call('callMountedAction')->assertForbidden();
        $this->get('/admin/postazioni')->assertForbidden();
        Livewire::test(ManageWorkstations::class)->assertForbidden();
        $this->assertSame('Nome iniziale', $local->refresh()->name);
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-'.Company::query()->count(), 'name' => 'Azienda test', 'last_seen_at' => now(), 'is_present' => true])->save();

        return $company;
    }
}
