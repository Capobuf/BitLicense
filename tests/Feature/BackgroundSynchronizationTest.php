<?php

namespace Tests\Feature;

use App\Filament\Pages\Licenze;
use App\Filament\Resources\Companies\Pages\ManageCompanies;
use App\Integrations\GravityZone\CompanySynchronizer;
use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\GravityZoneSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class BackgroundSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        GravityZoneSetting::create(['id' => 1, 'api_url' => 'https://gravityzone.example.test/api', 'api_key' => 'fake-test-key']);
        $this->actingAs(User::factory()->administrator()->create());
    }

    public function test_both_pages_queue_one_request_without_api_calls_or_exposing_credentials(): void
    {
        foreach ([Licenze::class, ManageCompanies::class] as $page) {
            Cache::forget('gravityzone-sync-requested');
            $component = Livewire::test($page)->assertDontSee('fake-test-key');
            $this->assertTrue($component->instance()->getAction('synchronize')->isIconButton());
            $component->callAction('synchronize')->assertNotified('Aggiornamento richiesto');
            $this->assertTrue(Cache::get('gravityzone-sync-requested'));
            $component->callAction('synchronize')->assertNotified('Aggiornamento già richiesto o in corso');
        }
        Http::assertNothingSent();
    }

    public function test_pages_report_the_requested_update_outcome_once_after_it_runs(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 11)->setTime(10, 0));
        $settings = GravityZoneSetting::find(1);
        $settings->forceFill(['companies_sync_attempted_at' => now()->subDay(), 'companies_sync_error' => 'Errore precedente.'])->save();
        Livewire::test(Licenze::class)->callAction('synchronize')->assertNotified('Aggiornamento richiesto');
        Livewire::test(ManageCompanies::class)->assertNotNotified('Aggiornamento GravityZone non riuscito');

        $this->travel(1)->minutes();
        $settings->forceFill(['companies_sync_attempted_at' => now(), 'companies_sync_error' => GravityZoneSetting::SYNC_IN_PROGRESS])->save();
        Livewire::test(Licenze::class)->assertNotNotified('Aggiornamento GravityZone non riuscito');
        $settings->forceFill(['companies_sync_error' => 'Accesso GravityZone negato. Verifica la chiave API e le sue autorizzazioni.'])->save();
        Livewire::test(ManageCompanies::class)->assertNotified('Aggiornamento GravityZone non riuscito');
        $this->assertNull(session('gravityzone-sync-requested-at'));
        Livewire::test(Licenze::class)->assertNotNotified('Aggiornamento GravityZone non riuscito');

        Cache::forget('gravityzone-sync-requested');
        Livewire::test(ManageCompanies::class)->callAction('synchronize')->assertNotified('Aggiornamento richiesto');
        $this->travel(1)->minutes();
        $settings->forceFill([
            'companies_sync_attempted_at' => now(), 'companies_sync_error' => null,
            'endpoints_sync_attempted_at' => now(), 'endpoints_sync_error' => GravityZoneSetting::SYNC_IN_PROGRESS,
        ])->save();
        Livewire::test(Licenze::class)->assertNotNotified('Aggiornamento GravityZone completato');
        $settings->forceFill(['endpoints_sync_error' => null])->save();
        Livewire::test(Licenze::class)->assertNotified('Aggiornamento GravityZone completato');
        $this->assertNull(session('gravityzone-sync-requested-at'));
        Http::assertNothingSent();
    }

    public function test_operators_and_demoted_or_disabled_admins_cannot_request_an_update(): void
    {
        foreach ([Licenze::class, ManageCompanies::class] as $page) {
            $user = User::factory()->administrator()->create();
            $this->actingAs($user);
            $component = Livewire::test($page);
            $user->update(['role' => 'operator']);
            $this->actingAs($user->fresh());
            $component->call('mountAction', 'synchronize')->assertActionNotMounted();
            Livewire::test($page)->assertActionHidden('synchronize')->call('mountAction', 'synchronize')->assertActionNotMounted();
            $this->assertFalse(Cache::has('gravityzone-sync-requested'));
            $user->update(['role' => 'admin', 'is_active' => false]);
            $this->actingAs($user->fresh());
            $component->call('mountAction', 'synchronize')->assertForbidden();
        }
        $this->assertFalse(Cache::has('gravityzone-sync-requested'));
        Http::assertNothingSent();
    }

    public function test_missing_configuration_and_a_running_update_do_not_queue_requests(): void
    {
        $lock = Cache::lock('gravityzone-refresh', 3600);
        $this->assertTrue($lock->get());
        Livewire::test(Licenze::class)->callAction('synchronize')->assertNotified('Aggiornamento già richiesto o in corso');
        $this->assertFalse(Cache::has('gravityzone-sync-requested'));
        $lock->release();
        GravityZoneSetting::find(1)->delete();
        Livewire::test(ManageCompanies::class)->callAction('synchronize')->assertNotified('Configura prima GravityZone in Impostazioni');
        $this->assertFalse(Cache::has('gravityzone-sync-requested'));
        Http::assertNothingSent();
    }

    public function test_command_consumes_pending_and_updates_companies_before_computers(): void
    {
        Cache::forever('gravityzone-sync-requested', true);
        $this->mock(CompanySynchronizer::class)->shouldReceive('sync')->once()->globally()->ordered()->andReturnUsing(function (): int {
            $this->assertFalse(Cache::has('gravityzone-sync-requested'));
            Livewire::test(ManageCompanies::class)->callAction('synchronize')->assertNotified('Aggiornamento già richiesto o in corso');

            return 1;
        });
        $this->mock(EndpointSynchronizer::class)->shouldReceive('sync')->once()->globally()->ordered()->andReturn(2);
        $this->artisan('bitlicense:sync')->assertSuccessful();
        $this->assertFalse(Cache::has('gravityzone-sync-requested'));
        Http::assertNothingSent();
    }

    public function test_busy_command_keeps_pending_and_failure_stops_computers_and_releases_lock(): void
    {
        Cache::forever('gravityzone-sync-requested', true);
        $lock = Cache::lock('gravityzone-refresh', 3600);
        $this->assertTrue($lock->get());
        $this->artisan('bitlicense:sync')->assertSuccessful();
        $this->assertTrue(Cache::get('gravityzone-sync-requested'));
        $this->artisan('bitlicense:sync-companies')->assertFailed();
        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertTrue(Cache::get('gravityzone-sync-requested'));
        $lock->release();
        $this->mock(CompanySynchronizer::class)->shouldReceive('sync')->once()->andThrow(new GravityZoneException('Lettura non riuscita.'));
        $this->mock(EndpointSynchronizer::class)->shouldNotReceive('sync');
        $this->artisan('bitlicense:sync')->expectsOutput('Lettura non riuscita.')->assertFailed();
        $this->assertFalse(Cache::has('gravityzone-sync-requested'));
        $this->assertTrue($lock->get());
        $lock->release();
        Http::assertNothingSent();
    }

    public function test_endpoint_failure_returns_failure_without_requeueing_and_releases_lock(): void
    {
        Cache::forever('gravityzone-sync-requested', true);
        $this->mock(CompanySynchronizer::class)->shouldReceive('sync')->once()->andReturn(1);
        $this->mock(EndpointSynchronizer::class)->shouldReceive('sync')->once()->andThrow(new GravityZoneException('Inventario incompleto.'));
        $this->artisan('bitlicense:sync')->expectsOutput('Inventario incompleto.')->assertFailed();
        $this->assertFalse(Cache::has('gravityzone-sync-requested'));
        $lock = Cache::lock('gravityzone-refresh', 3600);
        $this->assertTrue($lock->get());
        $lock->release();
        Http::assertNothingSent();
    }

    public function test_scheduler_runs_only_at_three_rome_time_or_for_an_explicit_request(): void
    {
        $events = collect(app(Schedule::class)->events());
        $this->assertCount(1, $events);
        $event = $events->sole();
        $this->assertStringContainsString('bitlicense:sync', $event->command);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('Europe/Rome', $event->timezone);
        foreach (['2026-11-04', '2026-07-04'] as $day) {
            foreach (['02:59' => false, '03:00' => true, '03:01' => false, '04:00' => false, '12:00' => false] as $time => $expected) {
                $this->travelTo(new \DateTimeImmutable("{$day} {$time} Europe/Rome"));
                $this->assertSame($expected, $event->filtersPass($this->app));
            }
        }
        Cache::forever('gravityzone-sync-requested', true);
        $this->assertTrue($event->filtersPass($this->app));
        Http::assertNothingSent();
    }
}
