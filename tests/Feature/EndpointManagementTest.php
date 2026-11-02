<?php

namespace Tests\Feature;

use App\Filament\Resources\Endpoints\Pages\ManageEndpoints;
use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\Company;
use App\Models\Endpoint;
use App\Models\GravityZoneSetting;
use App\Models\User;
use App\Models\Workstation;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class EndpointManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
    }

    public function test_new_endpoints_remain_pending_and_rendering_or_viewing_does_not_create_local_data(): void
    {
        $this->actingAs(User::factory()->create());
        $endpoint = $this->endpoint($this->company());
        $this->get('/admin/endpoint')->assertOk();
        $page = Livewire::test(ManageEndpoints::class)->assertCanSeeTableRecords([$endpoint])->assertSee('Da verificare')
            ->assertActionDoesNotExist('create')->assertActionDoesNotExist(TestAction::make('edit')->table($endpoint))
            ->assertActionDoesNotExist(TestAction::make('delete')->table($endpoint))
            ->mountAction(TestAction::make('view')->table($endpoint));
        $details = $page->instance()->mountedActionSchema0->toHtml();
        $this->assertStringContainsString($endpoint->gravityzone_id, $details);
        $this->assertStringContainsString('Non disponibile', $details);
        $this->assertDatabaseCount('workstations', 0);
        Http::assertNothingSent();
    }

    public function test_an_operator_explicitly_selects_the_correct_homonymous_local_workstation(): void
    {
        $this->actingAs(User::factory()->create());
        $company = $this->company();
        $endpoint = $this->endpoint($company);
        $first = Workstation::query()->create(['company_id' => $company->id, 'name' => 'PC identico']);
        $second = Workstation::query()->create(['company_id' => $company->id, 'name' => 'PC identico']);

        $page = Livewire::test(ManageEndpoints::class)->mountAction(TestAction::make('link')->table($endpoint));
        $choices = $page->instance()->mountedActionSchema0->toHtml();
        $this->assertStringContainsString('#'.$first->id, $choices);
        $this->assertStringContainsString('#'.$second->id, $choices);
        $page->fillForm(['workstation_id' => $second->id])->callMountedAction()->assertHasNoFormErrors()
            ->assertNotified('Postazione associata');

        $this->assertNull($first->refresh()->endpoint_id);
        $this->assertSame($endpoint->id, $second->refresh()->endpoint_id);
        Livewire::test(ManageEndpoints::class)->assertSee('Collegato')
            ->assertActionHidden(TestAction::make('link')->table($endpoint))
            ->filterTable('needs_review')->assertCanNotSeeTableRecords([$endpoint]);
    }

    public function test_foreign_company_and_already_linked_workstation_choices_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $company = $this->company();
        $endpoint = $this->endpoint($company);
        $foreign = Workstation::query()->create(['company_id' => $this->company()->id, 'name' => 'Postazione altra azienda']);
        $linked = Workstation::query()->create(['company_id' => $company->id, 'name' => 'Già associata']);
        $linked->linkEndpoint($this->endpoint($company));

        foreach ([$foreign, $linked] as $invalid) {
            $page = Livewire::test(ManageEndpoints::class)
                ->callAction(TestAction::make('link')->table($endpoint), data: ['workstation_id' => $invalid->id])
                ->assertHasFormErrors(['workstation_id']);
            view()->share('errors', (new ViewErrorBag)->put('default', $page->instance()->getErrorBag()));
            $this->assertStringContainsString('Il valore selezionato per postazione locale non è valido.', $page->instance()->mountedActionSchema0->toHtml());
        }
        $this->assertNull($foreign->refresh()->endpoint_id);
        $this->assertNotSame($endpoint->id, $linked->refresh()->endpoint_id);
    }

    public function test_an_operator_can_explicitly_create_a_local_workstation_from_an_endpoint(): void
    {
        $this->actingAs(User::factory()->create());
        $endpoint = $this->endpoint($this->company());
        Livewire::test(ManageEndpoints::class)->mountAction(TestAction::make('createWorkstation')->table($endpoint))
            ->assertActionDataSet(['name' => $endpoint->name])->fillForm(['name' => 'Nome commerciale locale'])
            ->set('mountedActions.0.data.company_id', $this->company()->id)
            ->set('mountedActions.0.data.name_remote', 'Manipolato')->callMountedAction()->assertHasNoFormErrors();
        $local = Workstation::query()->sole();
        $this->assertSame($endpoint->company_id, $local->company_id);
        $this->assertSame('Nome commerciale locale', $local->name);
        $this->assertSame($endpoint->id, $local->endpoint_id);
        $this->assertSame('PC tecnico', $endpoint->refresh()->name);
    }

    public function test_company_changes_and_absent_endpoints_remain_visible_without_automatic_reassignment(): void
    {
        $this->actingAs(User::factory()->create());
        $company = $this->company();
        $endpoint = $this->endpoint($company);
        $local = Workstation::query()->create(['company_id' => $company->id, 'name' => 'Nome locale conservato']);
        $local->linkEndpoint($endpoint);
        $endpoint->forceFill(['company_id' => $this->company()->id, 'is_present' => false])->save();
        $page = Livewire::test(ManageEndpoints::class)->assertSee('Da verificare')->assertSee('Non rilevato')
            ->filterTable('needs_review')->assertCanSeeTableRecords([$endpoint])
            ->assertActionHidden(TestAction::make('link')->table($endpoint))
            ->mountAction(TestAction::make('view')->table($endpoint));
        $this->assertStringContainsString('Associazione da verificare', $page->instance()->mountedActionSchema0->toHtml());
        $this->assertSame($company->id, $local->refresh()->company_id);
        $this->assertSame($endpoint->id, $local->endpoint_id);
    }

    public function test_sync_requires_an_administrator_and_rechecks_a_mounted_action(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);
        $this->mock(EndpointSynchronizer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')->once()->andReturn(2);
        });
        Livewire::test(ManageEndpoints::class)->callAction('synchronize')->assertNotified('Sincronizzazione endpoint completata');
        $this->mock(EndpointSynchronizer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')->once()->andThrow(new GravityZoneException('Lettura non riuscita.'));
        });
        Livewire::test(ManageEndpoints::class)->callAction('synchronize')->assertNotified('Sincronizzazione endpoint non completata');
        $this->mock(EndpointSynchronizer::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sync');
        });
        $page = Livewire::test(ManageEndpoints::class)->mountAction('synchronize');
        $admin->update(['role' => 'operator']);
        $this->actingAs($admin->fresh());
        $page->call('callMountedAction')->assertStatus(200);
        Livewire::test(ManageEndpoints::class)->assertActionHidden('synchronize')
            ->call('mountAction', 'synchronize')->assertActionNotMounted();
    }

    public function test_disabled_users_cannot_access_or_link_with_a_previously_open_action(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $endpoint = $this->endpoint($this->company());
        $local = Workstation::query()->create(['company_id' => $endpoint->company_id, 'name' => 'PC locale']);
        $page = Livewire::test(ManageEndpoints::class)->mountAction(TestAction::make('link')->table($endpoint))
            ->fillForm(['workstation_id' => $local->id]);
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh());
        $page->call('callMountedAction')->assertForbidden();
        $this->get('/admin/endpoint')->assertForbidden();
        $this->assertNull($local->refresh()->endpoint_id);
    }

    public function test_sync_dates_and_company_errors_are_visible_even_without_any_endpoint_and_no_secrets_are_rendered(): void
    {
        $this->actingAs(User::factory()->create());
        $this->company()->forceFill(['endpoints_sync_error' => 'Lettura endpoint azienda non riuscita.'])->save();
        GravityZoneSetting::query()->create(['id' => 1, 'api_url' => 'https://example.test/api', 'api_key' => 'not-rendered-key'])
            ->forceFill(['endpoints_sync_attempted_at' => '2026-11-02 12:00:00', 'endpoints_sync_error' => 'Sincronizzazione incompleta.'])->save();
        $page = Livewire::test(ManageEndpoints::class)->assertSee('02/11/2026 12:00')->assertSee('Sincronizzazione incompleta.')
            ->assertActionVisible('syncErrors')->mountAction('syncErrors')->assertDontSee('not-rendered-key');
        $this->assertStringContainsString('Lettura endpoint azienda non riuscita.', $page->instance()->mountedActionSchema0->toHtml());
        Http::assertNothingSent();
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-'.Company::query()->count(), 'name' => 'Azienda test', 'last_seen_at' => now(), 'is_present' => true])->save();

        return $company;
    }

    private function endpoint(Company $company): Endpoint
    {
        $endpoint = new Endpoint;
        $endpoint->forceFill(['company_id' => $company->id, 'gravityzone_id' => 'endpoint-'.Endpoint::query()->count(), 'name' => 'PC tecnico', 'is_present' => true, 'last_seen_at' => now()])->save();

        return $endpoint;
    }
}
