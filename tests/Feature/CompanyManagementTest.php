<?php

namespace Tests\Feature;

use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Integrations\GravityZone\CompanySynchronizer;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\Company;
use App\Models\GravityZoneSetting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class CompanyManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
    }

    public function test_an_operator_edits_only_local_contacts_and_notes(): void
    {
        $this->actingAs(User::factory()->create());
        $company = $this->company();
        $this->get('/admin/aziende')->assertOk();

        Livewire::test(ManageCompanies::class)
            ->mountAction(TestAction::make('edit')->table($company))
            ->fillForm([
                'contact_name' => 'Referente locale',
                'contact_email' => 'referente@example.test',
                'contact_phone' => '+39 0123 456789',
                'notes' => 'Note da conservare',
            ])
            ->set('mountedActions.0.data.name', 'Nome alterato')
            ->set('mountedActions.0.data.gravityzone_id', 'alterato')
            ->set('mountedActions.0.data.technical_expires_on', '2099-12-31')
            ->set('mountedActions.0.data.is_present', false)
            ->set('mountedActions.0.data.sync_error', 'alterato')
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $company->refresh();
        $this->assertSame('Referente locale', $company->contact_name);
        $this->assertSame('referente@example.test', $company->contact_email);
        $this->assertSame('+39 0123 456789', $company->contact_phone);
        $this->assertSame('Note da conservare', $company->notes);
        $this->assertSame('Azienda di prova', $company->name);
        $this->assertSame('company-test', $company->gravityzone_id);
        $this->assertSame('2027-04-20', $company->technical_expires_on->toDateString());
        $this->assertTrue($company->is_present);
        $this->assertNull($company->sync_error);
        Http::assertNothingSent();
    }

    public function test_contact_email_is_validated_and_remote_companies_cannot_be_created_or_deleted(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $company = $this->company();

        Livewire::test(ManageCompanies::class)
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist(TestAction::make('delete')->table($company))
            ->callAction(TestAction::make('edit')->table($company), data: ['contact_email' => 'non-email'])
            ->assertHasFormErrors(['contact_email' => 'email']);

        $this->assertFalse(Gate::allows('create', Company::class));
        $this->assertFalse(Gate::allows('delete', $company));
        $this->assertFalse(Gate::allows('deleteAny', Company::class));
        $this->assertNull($company->refresh()->contact_email);
    }

    public function test_search_filters_and_missing_or_stale_license_information(): void
    {
        $this->actingAs(User::factory()->create());
        $present = $this->company(['contact_name' => 'Referente cercabile']);
        $absent = $this->company(['gravityzone_id' => 'absent', 'name' => 'Azienda assente', 'is_present' => false, 'technical_expires_on' => null]);
        $failed = $this->company([
            'gravityzone_id' => 'failed', 'name' => 'Azienda in errore',
            'sync_error' => 'Lettura licenza non riuscita.',
        ]);

        Livewire::test(ManageCompanies::class)
            ->assertCanSeeTableRecords([$present, $absent, $failed])
            ->assertSee('Scadenza tecnica aziendale')
            ->assertSee('Non disponibile')->assertSee('Ultimo dato disponibile')
            ->searchTable('Referente cercabile')
            ->assertCanSeeTableRecords([$present])->assertCanNotSeeTableRecords([$absent, $failed])
            ->searchTable('')
            ->filterTable('is_present', false)
            ->assertCanSeeTableRecords([$absent])->assertCanNotSeeTableRecords([$present, $failed])
            ->resetTableFilters()->filterTable('sync_error')
            ->assertCanSeeTableRecords([$failed])->assertCanNotSeeTableRecords([$present, $absent]);

        Http::assertNothingSent();
    }

    public function test_an_administrator_explicitly_synchronizes_and_sees_sanitized_failures(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $this->mock(CompanySynchronizer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')->once()->andReturn(2);
        });

        Livewire::test(ManageCompanies::class)->callAction('synchronize')
            ->assertNotified('Sincronizzazione completata');

        $this->mock(CompanySynchronizer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sync')->once()->andThrow(new GravityZoneException('Lettura non riuscita. Riprova.'));
        });

        Livewire::test(ManageCompanies::class)->callAction('synchronize')
            ->assertNotified('Sincronizzazione non completata');
        Http::assertNothingSent();
    }

    public function test_an_operator_cannot_mount_sync_and_authorization_is_rechecked_after_demotion(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);
        $this->mock(CompanySynchronizer::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('sync');
        });
        $page = Livewire::test(ManageCompanies::class)->mountAction('synchronize');
        $admin->update(['role' => 'operator']);
        $this->actingAs($admin->fresh());
        $page->call('callMountedAction')->assertStatus(200);
        $this->assertFalse(Gate::allows('synchronize', Company::class));

        Livewire::test(ManageCompanies::class)->assertActionHidden('synchronize')
            ->call('mountAction', 'synchronize')->assertActionNotMounted();
    }

    public function test_disabled_users_cannot_access_companies_or_save_an_open_edit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $company = $this->company();
        $page = Livewire::test(ManageCompanies::class)
            ->mountAction(TestAction::make('edit')->table($company))->fillForm(['notes' => 'Non salvare']);
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh());

        $page->call('callMountedAction')->assertForbidden();
        $this->get('/admin/aziende')->assertForbidden();
        Livewire::test(ManageCompanies::class)->assertForbidden();
        $this->assertNull($company->refresh()->notes);
    }

    public function test_global_sync_status_displays_dates_and_errors_without_exposing_credentials_or_calling_the_api(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(ManageCompanies::class)->assertSee('GravityZone non configurato');

        GravityZoneSetting::query()->create(['id' => 1, 'api_url' => 'https://example.test/api', 'api_key' => 'never-render-this-key'])
            ->forceFill([
                'companies_sync_attempted_at' => '2026-11-02 12:00:00',
                'companies_synced_at' => '2026-11-01 12:00:00',
                'companies_sync_error' => 'Lettura elenco non riuscita.',
            ])->save();

        Livewire::test(ManageCompanies::class)->assertSee('02/11/2026 12:00')->assertSee('01/11/2026 12:00')
            ->assertSee('Lettura elenco non riuscita.')->assertDontSee('never-render-this-key');
        Http::assertNothingSent();
    }

    private function company(array $attributes = []): Company
    {
        $company = new Company;
        $company->forceFill(array_replace([
            'gravityzone_id' => 'company-test', 'name' => 'Azienda di prova',
            'technical_license_type' => 'Licenza', 'technical_expires_on' => '2027-04-20',
            'last_seen_at' => now(), 'license_synced_at' => now(), 'is_present' => true,
        ], $attributes))->save();

        return $company;
    }
}
