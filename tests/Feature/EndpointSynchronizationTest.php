<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneClient;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\Company;
use App\Models\Computer;
use App\Models\GravityZoneSetting;
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

    public function test_repeated_import_creates_computers_directly_and_preserves_commercial_data_on_rename(): void
    {
        $company = $this->company('company-1');
        $this->mockItems(['company-1' => [$this->item('endpoint-1')]]);
        $sync = app(EndpointSynchronizer::class);
        $this->assertSame(1, $sync->sync());
        $computer = Computer::sole();
        $this->assertNull($computer->activated_on);
        $this->assertNull($computer->expires_on);
        $this->assertNull($computer->product);
        $computer->updateCommercial([
            'activated_on' => '2026-05-01', 'expires_on' => '2027-05-01', 'notes' => 'Nota locale', 'amount' => '90,50',
            'product' => 'Suite locale', 'commercial_description' => 'Licenza acquistata', 'invoice_reference' => 'FT-1', 'quote_reference' => 'PR-1',
        ], 0);
        $computer->updateCommercial(['expires_on' => '2028-05-01'], 1);
        $this->assertNotEmpty($computer->history);
        $before = $computer->only(['activated_on', 'expires_on', 'notes', 'price_cents', 'product', 'commercial_description', 'invoice_reference', 'quote_reference', 'commercial_version', 'history']);
        $this->travel(1)->hours();
        $this->mockItems(['company-1' => [$this->item('endpoint-1', 'NOME-NUOVO')]]);

        $this->assertSame(1, $sync->sync());
        $this->assertDatabaseCount('computers', 1);
        $this->assertSame('NOME-NUOVO', $computer->refresh()->technical_name);
        $this->assertEquals($before, $computer->only(array_keys($before)));
        $this->assertTrue($computer->last_seen_at->equalTo(now()));
        $this->assertNull($company->refresh()->endpoints_sync_error);
        $this->assertTrue($company->endpoints_synced_at->equalTo(now()));
    }

    public function test_disappearance_reappearance_and_reinstallation_never_merge_computers_by_name(): void
    {
        $company = $this->company('company-1');
        $manual = Computer::create(['company_id' => $company->id, 'name' => 'PC-ESEMPIO']);
        $sync = app(EndpointSynchronizer::class);
        $this->mockItems(['company-1' => [$this->item('old-endpoint')]]);
        $sync->sync();
        $old = Computer::where('gravityzone_id', 'old-endpoint')->sole();
        $old->updateCommercial(['expires_on' => '2027-05-01'], 0);
        $this->mockItems(['company-1' => [$this->item('new-endpoint')]]);
        $sync->sync();
        $this->assertDatabaseCount('computers', 3);
        $this->assertFalse($old->refresh()->is_present);
        $this->assertSame('2027-05-01', $old->expires_on->toDateString());
        $this->assertNull(Computer::where('gravityzone_id', 'new-endpoint')->sole()->expires_on);
        $this->assertNull($manual->refresh()->gravityzone_id);
        $this->assertTrue($manual->is_present);

        $this->mockItems(['company-1' => [$this->item('new-endpoint'), $this->item('old-endpoint')]]);
        $sync->sync();
        $this->assertDatabaseCount('computers', 3);
        $this->assertTrue($old->refresh()->is_present);
        $this->assertSame('2027-05-01', $old->expires_on->toDateString());
    }

    public function test_remote_company_moves_in_either_direction_preserve_original_local_company_and_commercial_data(): void
    {
        $first = $this->company('company-1');
        $second = $this->company('company-2');
        $this->mockItems(['company-1' => [$this->item('endpoint-1')], 'company-2' => []]);
        app(EndpointSynchronizer::class)->sync();
        $computer = Computer::sole();
        $computer->updateCommercial(['notes' => 'Storico originale', 'expires_on' => '2027-05-01'], 0);
        $this->mockItems(['company-1' => [], 'company-2' => [$this->item('endpoint-1')]]);
        app(EndpointSynchronizer::class)->sync();

        $this->assertDatabaseCount('computers', 1);
        $this->assertSame($second->id, $computer->refresh()->gravityzone_company_id);
        $this->assertSame($first->id, $computer->company_id);
        $this->assertSame('Storico originale', $computer->notes);
        $this->assertSame('2027-05-01', $computer->expires_on->toDateString());
        $this->assertTrue($computer->is_present);
        $this->mockItems(['company-1' => [$this->item('endpoint-1')], 'company-2' => []]);
        app(EndpointSynchronizer::class)->sync();
        $this->assertSame($first->id, $computer->refresh()->gravityzone_company_id);
        $this->assertTrue($computer->is_present);
    }

    public function test_a_failed_later_page_cannot_partially_import_or_mark_existing_records_absent(): void
    {
        $company = $this->company('company-1');
        $this->mockItems(['company-1' => [$this->item('endpoint-1')]]);
        app(EndpointSynchronizer::class)->sync();
        $before = Computer::sole()->toArray();
        $lastSuccess = $company->refresh()->endpoints_synced_at;
        $this->travel(1)->hours();
        $this->mock(GravityZoneClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getNetworkInventoryItems')->once()->andThrow(new GravityZoneException('Risposta della pagina successiva non valida.'));
        });

        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertSame($before, Computer::sole()->toArray());
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
        $before = Computer::sole()->toArray();
        $this->mock(GravityZoneClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getNetworkInventoryItems')->once()->withArgs(fn ($settings, $id) => $id === 'company-1')
                ->andThrow(new GravityZoneException('Limite richieste. Riprova tra 90 secondi.', stopSynchronization: true));
        });
        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertSame($before, Computer::sole()->toArray());
        $this->assertStringContainsString('90 secondi', $second->refresh()->endpoints_sync_error);
    }

    public function test_a_duplicate_between_companies_is_reported_before_changing_any_ambiguous_endpoint_or_its_links(): void
    {
        $first = $this->company('company-1');
        $second = $this->company('company-2');
        $this->mockItems(['company-1' => [], 'company-2' => [$this->item('same-id')]]);
        app(EndpointSynchronizer::class)->sync();
        $endpoint = Computer::sole();
        $endpoint->updateCommercial(['notes' => 'Nota da conservare'], 0);
        $before = $endpoint->toArray();
        $this->travel(1)->hours();
        $this->mockItems(['company-1' => [$this->item('same-id', 'Ambigua')], 'company-2' => [$this->item('same-id')]]);

        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertDatabaseCount('computers', 1);
        $this->assertSame($before, $endpoint->refresh()->toArray());
        $this->assertSame($second->id, $endpoint->company_id);
        $this->assertStringContainsString('aziende diverse', $first->refresh()->endpoints_sync_error);
        $this->assertStringContainsString('aziende diverse', $second->refresh()->endpoints_sync_error);
    }

    public function test_database_failure_rolls_back_all_items_for_the_company_and_does_not_mark_absences(): void
    {
        $company = $this->company('company-1');
        $this->mockItems(['company-1' => [$this->item('endpoint-1')]]);
        app(EndpointSynchronizer::class)->sync();
        $before = Computer::sole()->toArray();
        $lastSuccess = $company->refresh()->endpoints_synced_at;
        DB::unprepared("CREATE TRIGGER reject_endpoint BEFORE INSERT ON computers WHEN NEW.gravityzone_id = 'endpoint-2' BEGIN SELECT RAISE(ABORT, 'private-db-value'); END;");
        $this->mockItems(['company-1' => [$this->item('endpoint-1', 'Non salvare'), $this->item('endpoint-2')]]);
        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertDatabaseCount('computers', 1);
        $this->assertSame($before, Computer::sole()->toArray());
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
