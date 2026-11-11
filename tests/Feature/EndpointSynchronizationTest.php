<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneClient;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\Company;
use App\Models\Computer;
use App\Models\GravityZoneSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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
            $stop = new GravityZoneException('Limite richieste. Riprova tra 90 secondi.', stopSynchronization: true);
            $mock->shouldReceive('getNetworkInventoryItems')->once()->withArgs(fn ($settings, $companies) => array_keys($companies) === ['company-1', 'company-2'])
                ->andReturn(['company-1' => $stop, 'company-2' => $stop]);
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

    public function test_the_shared_lock_blocks_company_and_endpoint_sync(): void
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
        Http::assertNothingSent();
    }

    public function test_unshared_partner_is_not_queried_and_does_not_lose_previous_inventory(): void
    {
        $partner = $this->company('partner-1');
        $partner->forceFill(['gravityzone_type' => 0, 'can_manage_security' => false, 'endpoints_sync_error' => 'Errore precedente'])->save();
        $pc = Computer::create(['company_id' => $partner->id, 'gravityzone_company_id' => $partner->id, 'gravityzone_id' => 'old-endpoint', 'name' => 'PC locale']);
        $before = $pc->refresh()->getAttributes();
        $this->mock(GravityZoneClient::class, fn (MockInterface $mock) => $mock->shouldNotReceive('getNetworkInventoryItems'));

        $this->assertSame(0, app(EndpointSynchronizer::class)->sync());
        $this->assertSame($before, $pc->refresh()->getAttributes());
        $this->assertNull($partner->refresh()->endpoints_sync_error);
        $this->assertNull($partner->endpoints_synced_at);
    }

    public function test_license_changes_errors_and_recovery_preserve_commercial_data_and_last_success(): void
    {
        $company = $this->company('company-1');
        $items = ['company-1' => [$this->item('endpoint-1'), $this->item('endpoint-2')]];
        $this->mockItems($items);
        $sync = app(EndpointSynchronizer::class);
        $sync->sync();
        $pc = Computer::where('gravityzone_id', 'endpoint-1')->sole();
        $pc->updateCommercial(['activated_on' => '2026-01-01', 'expires_on' => '2027-01-01', 'amount' => '42,50', 'notes' => 'Locale'], 0);
        $pc->updateCommercial(['expires_on' => '2028-01-01'], 1);
        $commercial = $pc->only(['company_id', 'activated_on', 'expires_on', 'price_cents', 'notes', 'history', 'commercial_version']);
        $checkedAt = $pc->license_checked_at;
        $this->assertSame(1, $pc->gravityzone_license_status);
        $this->assertNotNull($checkedAt);

        $this->travel(1)->hours();
        $this->mockItems($items, ['endpoint-1' => new GravityZoneException('Dettaglio non disponibile.'), 'endpoint-2' => 2]);
        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertSame(1, $pc->refresh()->gravityzone_license_status);
        $this->assertTrue($pc->license_checked_at->equalTo($checkedAt));
        $this->assertSame('Dettaglio non disponibile.', $pc->license_sync_error);
        $successful = Computer::where('gravityzone_id', 'endpoint-2')->sole();
        $this->assertSame(2, $successful->gravityzone_license_status);
        $this->assertSame('Licenza Scaduta', $successful->gravityzoneLicenseDisplay()['label']);
        $this->assertStringContainsString('1 computer', $company->refresh()->endpoints_sync_error);
        $this->assertTrue($pc->is_present);
        $this->assertEquals($commercial, $pc->only(array_keys($commercial)));

        $this->travel(1)->hours();
        $this->mockItems($items, ['endpoint-1' => 2, 'endpoint-2' => null]);
        $this->assertSame(2, $sync->sync());
        $this->assertSame(2, $pc->refresh()->gravityzone_license_status);
        $this->assertTrue($pc->license_checked_at->equalTo(now()));
        $this->assertNull($pc->license_sync_error);
        $unknown = Computer::where('gravityzone_id', 'endpoint-2')->sole();
        $this->assertNull($unknown->gravityzone_license_status);
        $this->assertTrue($unknown->license_checked_at->equalTo(now()));
        $this->assertNull($company->refresh()->endpoints_sync_error);
        $this->assertEquals($commercial, $pc->only(array_keys($commercial)));
        $this->assertDatabaseCount('computers', 2);
    }

    public function test_license_rate_limit_stops_remaining_details_but_keeps_imported_inventory_and_prior_states(): void
    {
        $this->company('company-1');
        $this->company('company-2');
        $items = ['company-1' => [$this->item('endpoint-1'), $this->item('endpoint-2')], 'company-2' => [$this->item('endpoint-3')]];
        $this->mockItems($items);
        app(EndpointSynchronizer::class)->sync();
        $previous = Computer::orderBy('id')->get()->map->only(['gravityzone_license_status', 'license_checked_at'])->all();
        $this->travel(1)->hours();
        $this->mock(GravityZoneClient::class, function (MockInterface $mock) use ($items): void {
            $mock->shouldReceive('getNetworkInventoryItems')->once()->andReturnUsing(fn ($settings, array $companies) => array_intersect_key($items, $companies));
            $mock->shouldReceive('getManagedEndpointDetails')->once()->withArgs(fn ($settings, $ids, $companyId) => $ids === ['endpoint-1', 'endpoint-2'] && $companyId === 'company-1')
                ->andThrow(new GravityZoneException('Limite richieste. Riprova tra 90 secondi.', stopSynchronization: true));
        });

        $this->artisan('bitlicense:sync-endpoints')->assertFailed();
        $this->assertEquals($previous, Computer::orderBy('id')->get()->map->only(['gravityzone_license_status', 'license_checked_at'])->all());
        $this->assertSame(3, Computer::whereNotNull('license_sync_error')->where('is_present', true)->count());
        $this->assertSame(2, Company::whereNotNull('endpoints_sync_error')->count());
        $this->assertStringContainsString('90 secondi', GravityZoneSetting::find(1)->endpoints_sync_error);
    }

    public function test_only_present_managed_imports_request_license_details_and_unmanaged_state_is_cleared(): void
    {
        $company = $this->company('company-1');
        Computer::create(['company_id' => $company->id, 'name' => 'Manuale']);
        $this->mockItems(['company-1' => [$this->item('unmanaged'), $this->item('unknown'), $this->item('absent')]]);
        app(EndpointSynchronizer::class)->sync();
        $items = [array_replace($this->item('unmanaged'), ['is_managed' => false]), array_replace($this->item('unknown'), ['is_managed' => null])];
        $this->mock(GravityZoneClient::class, function (MockInterface $mock) use ($items): void {
            $mock->shouldReceive('getNetworkInventoryItems')->once()->andReturn(['company-1' => $items]);
            $mock->shouldNotReceive('getManagedEndpointDetails');
        });

        $this->assertSame(2, app(EndpointSynchronizer::class)->sync());
        foreach (Computer::whereIn('gravityzone_id', ['unmanaged', 'unknown'])->get() as $pc) {
            $this->assertNull($pc->gravityzone_license_status);
            $this->assertNull($pc->license_checked_at);
            $this->assertNull($pc->license_sync_error);
        }
        $absent = Computer::where('gravityzone_id', 'absent')->sole();
        $this->assertFalse($absent->is_present);
        $this->assertSame(1, $absent->gravityzone_license_status);
        $this->assertDatabaseCount('computers', 4);
    }

    public function test_first_seen_fills_missing_activation_once_preserves_expiry_and_rejects_stale_forms(): void
    {
        $company = $this->company('company-1');
        $items = [$this->item('existing'), $this->item('empty'), $this->item('conflict'), $this->item('cleared')];
        $this->mockItems(['company-1' => $items]);
        $sync = app(EndpointSynchronizer::class);
        $sync->sync();
        $existing = Computer::where('gravityzone_id', 'existing')->sole();
        $existing->updateCommercial(['activated_on' => '2026-01-01', 'expires_on' => '2027-01-01'], 0);
        $before = $existing->getAttributes();
        $empty = Computer::where('gravityzone_id', 'empty')->sole();
        $empty->updateCommercial(['expires_on' => '2027-03-01', 'amount' => '42,50'], 0);
        $version = $empty->commercial_version;
        $conflict = Computer::where('gravityzone_id', 'conflict')->sole();
        $conflict->updateCommercial(['expires_on' => '2025-01-01'], 0);
        $cleared = Computer::where('gravityzone_id', 'cleared')->sole();
        $cleared->updateCommercial(['activated_on' => '2025-01-01'], 0);
        $cleared->updateCommercial(['activated_on' => null], 1);
        $items = array_map(fn ($item) => $item + ['first_seen_on' => '2026-02-28'], $items);
        $items[] = $this->item('new') + ['first_seen_on' => '2024-02-29'];
        $this->mockItems(['company-1' => $items]);
        $sync->sync();
        $this->assertSame('2026-02-28', $empty->refresh()->activated_on->toDateString());
        $this->assertTrue($empty->activation_from_first_seen);
        $this->assertSame('2027-03-01', $empty->expires_on->toDateString());
        $this->assertSame(4250, $empty->price_cents);
        $this->assertSame($version + 2, $empty->commercial_version);
        $this->assertNull($empty->history[0]['previous']['activated_on']);
        $this->assertSame('2027-03-01', $empty->history[0]['previous']['expires_on']);
        foreach (['activated_on', 'expires_on', 'history'] as $field) {
            $this->assertSame($before[$field], $existing->refresh()->getRawOriginal($field));
        }
        $this->assertNull($conflict->refresh()->activated_on);
        $this->assertNull($cleared->refresh()->activated_on);
        $new = Computer::where('gravityzone_id', 'new')->sole();
        $this->assertSame('2024-02-29', $new->activated_on->toDateString());
        $this->assertNull($new->expires_on);
        $after = $empty->getAttributes();
        $sync->sync();
        $this->assertSame($after, $empty->refresh()->getAttributes());
        $this->assertSame('initial', $new->effectivePeriod()->operation->kind);
        $sync->sync();
        $this->assertSame('2024-02-29', $new->refresh()->activated_on->toDateString());
        $this->expectException(ValidationException::class);
        $empty->updateCommercial(['activated_on' => null], $version);
    }

    public function test_auto_activation_does_not_block_reconciliation_or_transfer_and_never_returns_after_transfer(): void
    {
        $company = $this->company('company-1');
        $items = [
            $this->item('reconcile') + ['first_seen_on' => '2026-02-28'],
            $this->item('replacement') + ['first_seen_on' => '2026-03-01'],
        ];
        $this->mockItems(['company-1' => $items]);
        $sync = app(EndpointSynchronizer::class);
        $sync->sync();
        $remote = Computer::where('gravityzone_id', 'reconcile')->sole();
        $replacement = Computer::where('gravityzone_id', 'replacement')->sole();
        $this->assertFalse($remote->hasCommercialData());
        $manual = Computer::create(['company_id' => $company->id, 'name' => 'PC manuale']);
        $manual->updateCommercial(['activated_on' => '2026-01-01', 'expires_on' => '2027-01-01'], 0);
        $manual->reconcileWith($remote, $manual->commercial_version);
        $this->assertSame('2026-02-28', $manual->first_seen_on->toDateString());
        $this->assertSame('2026-01-01', $manual->activated_on->toDateString());
        $this->assertFalse($manual->activation_from_first_seen);
        $manual->transferTo($replacement, $manual->commercial_version);
        $this->assertSame('2026-01-01', $replacement->activated_on->toDateString());
        $this->assertFalse($replacement->activation_from_first_seen);
        $sync->sync();
        $this->assertNull($manual->refresh()->activated_on);
        $this->assertSame('2026-01-01', $replacement->refresh()->activated_on->toDateString());
        $this->assertSame('2026-03-01', $replacement->first_seen_on->toDateString());
        $this->assertDatabaseCount('computers', 2);
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

    private function mockItems(array $items, array $licenses = []): void
    {
        $this->mock(GravityZoneClient::class, function (MockInterface $mock) use ($items, $licenses): void {
            $mock->shouldReceive('getNetworkInventoryItems')->andReturnUsing(fn ($settings, array $companies) => array_map(
                fn (string $companyId) => $items[$companyId] ?? [], array_combine(array_keys($companies), array_keys($companies)),
            ));
            // Mirrors the client: a stopping failure is returned for every endpoint not yet read.
            $mock->shouldReceive('getManagedEndpointDetails')->andReturnUsing(function ($settings, array $endpointIds) use ($licenses): array {
                $results = [];
                $stop = null;
                foreach ($endpointIds as $endpointId) {
                    $results[$endpointId] = $stop ?? (array_key_exists($endpointId, $licenses) ? $licenses[$endpointId] : 1);
                    if ($results[$endpointId] instanceof GravityZoneException && $results[$endpointId]->stopSynchronization) {
                        $stop = $results[$endpointId];
                    }
                }

                return $results;
            });
        });
    }
}
