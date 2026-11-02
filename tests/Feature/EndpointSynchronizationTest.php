<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneClient;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\Company;
use App\Models\Endpoint;
use App\Models\GravityZoneSetting;
use App\Models\Workstation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use Tests\TestCase;

class EndpointSynchronizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        GravityZoneSetting::create(['id' => 1, 'api_url' => 'https://gravityzone.example.test/api', 'api_key' => 'fake-test-key']);
    }

    public function test_repeated_import_renames_by_stable_id_without_creating_local_workstations_or_changing_their_data(): void
    {
        $company = $this->company('company-1');
        $this->mockItems(['company-1' => [$this->item('endpoint-1')]]);
        $sync = app(EndpointSynchronizer::class);
        $this->assertSame(1, $sync->sync());
        $endpoint = Endpoint::sole();
        $this->assertNull($endpoint->workstation);
        $this->assertDatabaseCount('workstations', 0);
        $local = Workstation::create(['company_id' => $company->id, 'name' => 'Nome commerciale', 'notes' => 'Nota locale']);
        $local->linkEndpoint($endpoint);
        $this->travel(1)->hours();
        $this->mockItems(['company-1' => [$this->item('endpoint-1', 'NOME-NUOVO')]]);

        $this->assertSame(1, $sync->sync());
        $this->assertDatabaseCount('endpoints', 1);
        $this->assertDatabaseCount('workstations', 1);
        $this->assertSame('NOME-NUOVO', $endpoint->refresh()->name);
        $this->assertTrue($endpoint->last_seen_at->equalTo(now()));
        $this->assertSame('Nome commerciale', $local->refresh()->name);
        $this->assertSame('Nota locale', $local->notes);
        $this->assertSame($endpoint->id, $local->endpoint_id);
        $this->assertNull($company->refresh()->endpoints_sync_error);
        $this->assertTrue($company->endpoints_synced_at->equalTo(now()));
    }

    public function test_disappearance_reappearance_and_a_reinstalled_device_with_the_same_name_never_merge_local_records(): void
    {
        $company = $this->company('company-1');
        $sync = app(EndpointSynchronizer::class);
        $this->mockItems(['company-1' => [$this->item('old-endpoint')]]);
        $sync->sync();
        $old = Endpoint::sole();
        $local = Workstation::create(['company_id' => $company->id, 'name' => 'PC locale']);
        $local->linkEndpoint($old);
        $this->mockItems(['company-1' => [$this->item('new-endpoint')]]);
        $sync->sync();
        $this->assertDatabaseCount('endpoints', 2);
        $this->assertFalse($old->refresh()->is_present);
        $new = Endpoint::where('gravityzone_id', 'new-endpoint')->sole();
        $this->assertNull($new->workstation);
        $this->assertSame($old->id, $local->refresh()->endpoint_id);

        $this->mockItems(['company-1' => [$this->item('new-endpoint'), $this->item('old-endpoint')]]);
        $sync->sync();
        $this->assertDatabaseCount('endpoints', 2);
        $this->assertTrue($old->refresh()->is_present);
        $this->assertSame($old->id, $local->refresh()->endpoint_id);
    }

    public function test_a_remote_company_move_preserves_the_original_local_company_and_association(): void
    {
        $first = $this->company('company-1');
        $second = $this->company('company-2');
        $this->mockItems(['company-1' => [$this->item('endpoint-1')], 'company-2' => []]);
        app(EndpointSynchronizer::class)->sync();
        $endpoint = Endpoint::sole();
        $local = Workstation::create(['company_id' => $first->id, 'name' => 'PC locale', 'notes' => 'Storico originale']);
        $local->linkEndpoint($endpoint);
        $this->mockItems(['company-1' => [], 'company-2' => [$this->item('endpoint-1')]]);
        app(EndpointSynchronizer::class)->sync();

        $this->assertDatabaseCount('endpoints', 1);
        $this->assertSame($second->id, $endpoint->refresh()->company_id);
        $this->assertSame($first->id, $local->refresh()->company_id);
        $this->assertSame($endpoint->id, $local->endpoint_id);
        $this->assertSame('Storico originale', $local->notes);
    }

    public function test_a_failed_later_page_cannot_partially_import_or_mark_existing_records_absent(): void
    {
        $company = $this->company('company-1');
        $this->mockItems(['company-1' => [$this->item('endpoint-1')]]);
        app(EndpointSynchronizer::class)->sync();
        $before = Endpoint::sole()->toArray();
        $lastSuccess = $company->refresh()->endpoints_synced_at;
        $this->travel(1)->hours();
        $this->mock(GravityZoneClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getNetworkInventoryItems')->once()->andThrow(new GravityZoneException('Risposta della pagina successiva non valida.'));
        });

        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertSame($before, Endpoint::sole()->toArray());
        $this->assertTrue($company->refresh()->endpoints_synced_at->equalTo($lastSuccess));
        $this->assertSame('Risposta della pagina successiva non valida.', $company->endpoints_sync_error);
        $this->assertNotNull(GravityZoneSetting::find(1)->endpoints_sync_error);
    }

    public function test_a_network_or_rate_limit_failure_stops_other_company_reads_and_keeps_their_data(): void
    {
        $this->company('company-1');
        $second = $this->company('company-2');
        $this->mockItems(['company-1' => [], 'company-2' => [$this->item('endpoint-2')]]);
        app(EndpointSynchronizer::class)->sync();
        $before = Endpoint::sole()->toArray();
        $this->mock(GravityZoneClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getNetworkInventoryItems')->once()->withArgs(fn ($settings, $id) => $id === 'company-1')
                ->andThrow(new GravityZoneException('Limite richieste. Riprova tra 90 secondi.', stopSynchronization: true));
        });
        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertSame($before, Endpoint::sole()->toArray());
        $this->assertStringContainsString('90 secondi', $second->refresh()->endpoints_sync_error);
    }

    public function test_a_duplicate_between_companies_is_reported_before_changing_any_ambiguous_endpoint_or_its_links(): void
    {
        $first = $this->company('company-1');
        $second = $this->company('company-2');
        $this->mockItems(['company-1' => [], 'company-2' => [$this->item('same-id')]]);
        app(EndpointSynchronizer::class)->sync();
        $endpoint = Endpoint::sole();
        $local = Workstation::create(['company_id' => $second->id, 'name' => 'PC locale']);
        $local->linkEndpoint($endpoint);
        $before = $endpoint->toArray();
        $this->travel(1)->hours();
        $this->mockItems(['company-1' => [$this->item('same-id', 'Ambigua')], 'company-2' => [$this->item('same-id')]]);

        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertDatabaseCount('endpoints', 1);
        $this->assertSame($before, $endpoint->refresh()->toArray());
        $this->assertSame($endpoint->id, $local->refresh()->endpoint_id);
        $this->assertSame($second->id, $local->company_id);
        $this->assertStringContainsString('aziende diverse', $first->refresh()->endpoints_sync_error);
        $this->assertStringContainsString('aziende diverse', $second->refresh()->endpoints_sync_error);
    }

    public function test_database_failure_rolls_back_all_items_for_the_company_and_does_not_mark_absences(): void
    {
        $company = $this->company('company-1');
        $this->mockItems(['company-1' => [$this->item('endpoint-1')]]);
        app(EndpointSynchronizer::class)->sync();
        $before = Endpoint::sole()->toArray();
        $lastSuccess = $company->refresh()->endpoints_synced_at;
        DB::unprepared("CREATE TRIGGER reject_endpoint BEFORE INSERT ON endpoints WHEN NEW.gravityzone_id = 'endpoint-2' BEGIN SELECT RAISE(ABORT, 'private-db-value'); END;");
        $this->mockItems(['company-1' => [$this->item('endpoint-1', 'Non salvare'), $this->item('endpoint-2')]]);
        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertDatabaseCount('endpoints', 1);
        $this->assertSame($before, Endpoint::sole()->toArray());
        $this->assertTrue($company->refresh()->endpoints_synced_at->equalTo($lastSuccess));
        $error = GravityZoneSetting::find(1)->endpoints_sync_error;
        $this->assertStringContainsString('errore del database', $error);
        $this->assertStringNotContainsString('private-db-value', $error);
    }

    public function test_the_shared_lock_blocks_company_and_endpoint_sync_and_daily_scheduler_is_registered(): void
    {
        $lock = Cache::lock('gravityzone-sync', 3600);
        $this->assertTrue($lock->get());
        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->artisan('bitlicense:sync-companies')->assertFailed();
        $lock->release();
        $this->mockItems([]);
        $this->artisan('bitlicense:sync-endpoints')->assertSuccessful();
        $this->assertTrue($lock->get());
        $lock->release();
        $event = collect(app(Schedule::class)->events())->sole(fn ($event) => str_contains($event->command ?? '', 'bitlicense:sync-endpoints'));
        $this->assertSame('0 4 * * *', $event->expression);
        $this->assertSame('Europe/Rome', $event->timezone);
        Http::assertNothingSent();
    }

    private function company(string $externalId): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => $externalId, 'name' => 'Azienda esempio', 'last_seen_at' => now()])->save();

        return $company;
    }

    private function item(string $id, string $name = 'PC-ESEMPIO'): array
    {
        return ['id' => $id, 'name' => $name, 'platform' => 'Windows', 'is_managed' => true, 'is_isolated' => null];
    }

    private function mockItems(array $items): void
    {
        $this->mock(GravityZoneClient::class, function (MockInterface $mock) use ($items): void {
            $mock->shouldReceive('getNetworkInventoryItems')->andReturnUsing(fn ($settings, $companyId) => $items[$companyId] ?? []);
        });
    }
}
