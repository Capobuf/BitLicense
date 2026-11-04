<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\CompanySynchronizer;
use App\Models\Company;
use App\Models\Computer;
use App\Models\GravityZoneSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CompanySynchronizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        Sleep::fake();
        Mail::fake();
        GravityZoneSetting::create(['id' => 1, 'api_url' => 'https://gravityzone.example.test/api', 'api_key' => 'fake-test-key']);
    }

    public function test_repeated_sync_uses_external_ids_and_preserves_local_data_while_remote_data_changes(): void
    {
        $this->fakeCompanies([['id' => 'company-1', 'name' => 'Azienda esempio']]);
        $sync = app(CompanySynchronizer::class);
        $this->assertSame(1, $sync->sync());
        $company = Company::sole();
        $id = $company->id;
        $this->assertSame('2027-06-30', $company->technical_expires_on->toDateString());
        $this->assertFalse($company->is_suspended);
        $company->update(['contact_name' => 'Referente locale', 'contact_email' => 'contatto@example.test', 'contact_phone' => '+390000000', 'notes' => 'Nota commerciale locale']);

        $this->travel(1)->hours();
        $this->fakeCompanies([['id' => 'company-1', 'name' => 'Nome remoto aggiornato']], ['subscriptionType' => 3], suspended: true);
        $this->assertSame(1, $sync->sync());
        $this->assertDatabaseCount('companies', 1);
        $company->refresh();
        $this->assertSame($id, $company->id);
        $this->assertSame('Nome remoto aggiornato', $company->name);
        $this->assertSame('Referente locale', $company->contact_name);
        $this->assertSame('contatto@example.test', $company->contact_email);
        $this->assertSame('+390000000', $company->contact_phone);
        $this->assertSame('Nota commerciale locale', $company->notes);
        $this->assertSame('Mensile', $company->technical_license_type);
        $this->assertTrue($company->is_suspended);
        $this->assertNull($company->technical_expires_on);
        $this->assertNull($company->sync_error);
        $this->assertTrue($company->last_seen_at->equalTo(now()));
        $this->assertTrue($company->license_synced_at->equalTo(now()));
        $this->assertTrue(GravityZoneSetting::find(1)->companies_synced_at->equalTo(now()));
        $this->assertNull(GravityZoneSetting::find(1)->companies_sync_error);
        Sleep::assertSleptTimes(4);
        Http::assertSentCount(3);
        Mail::assertNothingSent();
    }

    public function test_absence_and_reappearance_keep_the_record_and_local_history_even_for_an_empty_remote_list(): void
    {
        $sync = app(CompanySynchronizer::class);
        $this->fakeCompanies([['id' => 'company-1', 'name' => 'Azienda esempio']]);
        $sync->sync();
        $company = Company::sole();
        $company->update(['notes' => 'Da conservare']);
        $seenAt = $company->last_seen_at;
        $this->travel(1)->days();

        $this->fakeCompanies([]);
        $this->assertSame(0, $sync->sync());
        $this->assertFalse($company->refresh()->is_present);
        $this->assertSame('Da conservare', $company->notes);
        $this->assertTrue($company->last_seen_at->equalTo($seenAt));
        $this->assertSame('2027-06-30', $company->technical_expires_on->toDateString());

        $this->fakeCompanies([['id' => 'company-1', 'name' => 'Azienda rinominata']]);
        $sync->sync();
        $this->assertDatabaseCount('companies', 1);
        $this->assertTrue($company->refresh()->is_present);
        $this->assertSame('Da conservare', $company->notes);
        $this->assertSame('Azienda rinominata', $company->name);
    }

    public function test_company_product_refreshes_without_changing_local_commercial_data_and_clears_when_no_longer_provided(): void
    {
        $companies = [['id' => 'company-1', 'name' => 'Azienda esempio']];
        $this->fakeCompanies($companies, ['subscriptionType' => 3, 'assignedProductType' => 0, 'assignedProtectionModel' => 'mspSecure']);
        $sync = app(CompanySynchronizer::class);
        $sync->sync();
        $company = Company::sole();
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC locale']);
        $pc->updateCommercial(['product' => 'Annotazione locale', 'activated_on' => '2026-04-20', 'expires_on' => '2027-04-20'], 0);
        $pc->updateCommercial(['notes' => 'Nota conservata', 'expires_on' => '2027-04-21'], 1);
        $before = $pc->getAttributes();
        $this->assertSame('Endpoint Security · MSP Secure', $company->technical_product);

        $this->fakeCompanies($companies, ['subscriptionType' => 3, 'assignedProductType' => 3]);
        $sync->sync();
        $this->assertSame('Bitdefender EDR', $company->refresh()->technical_product);
        $this->assertSame($before, $pc->refresh()->getAttributes());

        $this->fakeCompanies($companies);
        $sync->sync();
        $this->assertNull($company->refresh()->technical_product);
        $this->assertSame($before, $pc->refresh()->getAttributes());
    }

    public function test_monthly_end_refresh_clears_missing_dates_and_preserves_computers_and_history_on_failure(): void
    {
        $companies = [['id' => 'company-1', 'name' => 'Azienda esempio']];
        $license = json_decode(file_get_contents(base_path('tests/Fixtures/GravityZone/license-monthly-end.json')), true)['result'];
        $this->fakeCompanies($companies, $license);
        $sync = app(CompanySynchronizer::class);
        $sync->sync();
        $company = Company::sole();
        $this->assertSame('2027-06-30', $company->technical_expires_on->toDateString());
        $readAt = $company->license_synced_at;
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC locale']);
        $pc->updateCommercial(['activated_on' => '2026-04-20', 'expires_on' => '2027-04-20', 'amount' => '79,90'], 0);
        $pc->updateCommercial(['notes' => 'Nota conservata', 'quote_reference' => 'PREV-TEST'], 1);
        $before = $pc->getAttributes();

        $this->travel(1)->hours();
        $license['endSubscription'] = '2027-02-29';
        $this->fakeCompanies($companies, $license);
        $this->artisan('bitlicense:sync-companies')->assertFailed();
        $company->refresh();
        $this->assertSame('2027-06-30', $company->technical_expires_on->toDateString());
        $this->assertTrue($company->license_synced_at->equalTo($readAt));
        $this->assertStringContainsString('scadenza tecnica non valida', $company->sync_error);
        $this->assertSame($before, $pc->refresh()->getAttributes());

        $license['endSubscription'] = '2027-07-31';
        $this->fakeCompanies($companies, $license);
        $this->assertSame(1, $sync->sync());
        $this->assertSame('2027-07-31', $company->refresh()->technical_expires_on->toDateString());
        unset($license['endSubscription']);
        $this->fakeCompanies($companies, $license);
        $this->assertSame(1, $sync->sync());
        $this->assertSame(1, $sync->sync());
        $this->assertNull($company->refresh()->technical_expires_on);
        $this->assertNull($company->sync_error);
        $this->assertDatabaseCount('companies', 1);
        $this->assertSame($before, $pc->refresh()->getAttributes());
    }

    public function test_network_failure_preserves_the_complete_last_import_and_records_a_safe_error(): void
    {
        $this->fakeCompanies([['id' => 'company-1', 'name' => 'Azienda esempio']]);
        app(CompanySynchronizer::class)->sync();
        $before = Company::sole()->toArray();
        $previousSync = GravityZoneSetting::find(1)->companies_synced_at;
        $this->travel(1)->hours();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('fake-test-key private-payload'));

        $this->artisan('bitlicense:sync-companies')->assertFailed();
        $this->assertSame($before, Company::sole()->toArray());
        $settings = GravityZoneSetting::find(1);
        $this->assertTrue($settings->companies_synced_at->equalTo($previousSync));
        $this->assertTrue($settings->companies_sync_attempted_at->equalTo(now()));
        $this->assertStringContainsString('errore di rete', $settings->companies_sync_error);
        $this->assertStringNotContainsString('fake-test-key', $settings->companies_sync_error);
        $this->assertStringNotContainsString('private-payload', $settings->companies_sync_error);
    }

    public function test_an_invalid_or_duplicate_remote_list_cannot_partly_import_or_mark_existing_companies_absent(): void
    {
        $this->fakeCompanies([['id' => 'company-1', 'name' => 'Azienda esistente']]);
        app(CompanySynchronizer::class)->sync();
        $before = Company::sole()->toArray();

        foreach ([
            [['id' => 'company-2', 'name' => 'Valida'], ['id' => 'company-3']],
            [['id' => 'company-2', 'name' => 'Valida'], ['id' => 'company-2', 'name' => 'Duplicata']],
        ] as $invalidList) {
            $this->fakeCompanies($invalidList);
            $this->artisan('bitlicense:sync-companies')->assertFailed();
            $this->assertDatabaseCount('companies', 1);
            $this->assertSame($before, Company::sole()->toArray());
        }
    }

    public function test_a_database_failure_rolls_back_the_entire_list_and_records_a_sanitized_error(): void
    {
        $this->fakeCompanies([['id' => 'company-1', 'name' => 'Prima']]);
        app(CompanySynchronizer::class)->sync();
        $before = Company::sole()->toArray();
        $previousSync = GravityZoneSetting::find(1)->companies_synced_at;
        $this->travel(1)->hours();
        DB::unprepared("CREATE TRIGGER reject_company BEFORE INSERT ON companies WHEN NEW.gravityzone_id = 'company-2' BEGIN SELECT RAISE(ABORT, 'private-db-value'); END;");
        $this->fakeCompanies([['id' => 'company-1', 'name' => 'Non salvare'], ['id' => 'company-2', 'name' => 'Rifiutata']]);

        $this->artisan('bitlicense:sync-companies')->assertFailed();
        $this->assertDatabaseCount('companies', 1);
        $this->assertSame($before, Company::sole()->toArray());
        $settings = GravityZoneSetting::find(1);
        $this->assertTrue($settings->companies_synced_at->equalTo($previousSync));
        $this->assertStringContainsString('errore del database', $settings->companies_sync_error);
        $this->assertStringNotContainsString('private-db-value', $settings->companies_sync_error);
        Http::assertSentCount(1);
    }

    public function test_license_failure_keeps_previous_license_and_contacts_and_continues_other_company_reads(): void
    {
        $companies = [['id' => 'company-1', 'name' => 'Prima'], ['id' => 'company-2', 'name' => 'Seconda']];
        $this->fakeCompanies($companies);
        app(CompanySynchronizer::class)->sync();
        $first = Company::where('gravityzone_id', 'company-1')->sole();
        $first->update(['notes' => 'Da conservare']);
        $licenseReadAt = $first->license_synced_at;
        $this->travel(1)->days();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => $request['method'] === 'getCompaniesList'
            ? Http::response($this->rpc($companies))
            : ($request['params']['companyId'] === 'company-1'
                ? Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'error' => ['code' => -32002, 'message' => 'fake-test-key']])
                : Http::response($this->rpc($request['method'] === 'getCompanyDetails'
                    ? ['id' => 'company-2', 'isSuspended' => false, 'type' => 1, 'canBeManagedByAbove' => true] : ['subscriptionType' => 3]))));

        $this->artisan('bitlicense:sync-companies')->assertFailed();
        $first->refresh();
        $this->assertSame('2027-06-30', $first->technical_expires_on->toDateString());
        $this->assertTrue($first->license_synced_at->equalTo($licenseReadAt));
        $this->assertSame('Da conservare', $first->notes);
        $this->assertNotNull($first->sync_error);
        $this->assertStringNotContainsString('fake-test-key', $first->sync_error);
        $second = Company::where('gravityzone_id', 'company-2')->sole();
        $this->assertNull($second->technical_expires_on);
        $this->assertNull($second->sync_error);
        $this->assertTrue($second->license_synced_at->equalTo(now()));
        $this->assertStringContainsString('Aziende con dati tecnici non aggiornati: 1', GravityZoneSetting::find(1)->companies_sync_error);
    }

    public function test_status_failure_keeps_previous_status_and_newly_read_license_without_touching_local_data(): void
    {
        $companies = [['id' => 'company-1', 'name' => 'Prima']];
        $this->fakeCompanies($companies, suspended: true);
        app(CompanySynchronizer::class)->sync();
        $company = Company::sole();
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC locale']);
        $pc->updateCommercial(['activated_on' => '2026-04-20', 'expires_on' => '2027-04-20', 'amount' => '79,90'], 0);
        $before = $pc->getAttributes();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response($request['method'] === 'getCompanyDetails'
            ? ['jsonrpc' => '2.0', 'id' => $request['id'], 'error' => ['code' => -32002, 'message' => 'private-data']]
            : $this->rpc($request['method'] === 'getCompaniesList' ? $companies : ['subscriptionType' => 3])));

        $this->artisan('bitlicense:sync-companies')->assertFailed();
        $company->refresh();
        $this->assertTrue($company->is_suspended);
        $this->assertSame('Mensile', $company->technical_license_type);
        $this->assertNull($company->technical_expires_on);
        $this->assertNotNull($company->sync_error);
        $this->assertStringNotContainsString('private-data', $company->sync_error);
        $this->assertSame($before, $pc->refresh()->getAttributes());

        $this->fakeCompanies($companies, suspended: null);
        app(CompanySynchronizer::class)->sync();
        $this->assertNull($company->refresh()->is_suspended);
        $this->assertNull($company->sync_error);
    }

    public function test_rate_limit_stops_remaining_requests_and_keeps_retry_after_visible(): void
    {
        $companies = [['id' => 'company-1', 'name' => 'Prima'], ['id' => 'company-2', 'name' => 'Seconda']];
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => $request['method'] === 'getCompaniesList'
            ? Http::response($this->rpc($companies))
            : Http::response('fake-test-key', 429, ['Retry-After' => '90']));

        $this->artisan('bitlicense:sync-companies')->assertFailed();
        Http::assertSentCount(2);
        $this->assertDatabaseCount('companies', 2);
        foreach (Company::all() as $company) {
            $this->assertTrue($company->is_present);
            $this->assertNull($company->license_synced_at);
            $this->assertNull($company->technical_expires_on);
            $this->assertStringContainsString('tra 90 secondi', $company->sync_error);
        }
        $this->assertStringContainsString('Aziende con dati tecnici non aggiornati: 2', GravityZoneSetting::find(1)->companies_sync_error);
    }

    public function test_missing_configuration_or_another_sync_blocks_requests_and_the_lock_is_released_after_failure(): void
    {
        $lock = Cache::lock('gravityzone-sync', 3600);
        $this->assertTrue($lock->get());
        $this->artisan('bitlicense:sync-companies')->assertFailed();
        Http::assertNothingSent();
        $lock->release();
        GravityZoneSetting::find(1)->delete();
        $this->artisan('bitlicense:sync-companies')->assertFailed();
        Http::assertNothingSent();
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_the_cli_uses_the_same_synchronizer(): void
    {
        $this->fakeCompanies([]);
        $this->artisan('bitlicense:sync-companies')->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_partner_clients_are_discovered_once_and_reappear_with_their_local_data(): void
    {
        $client = new Company;
        $client->forceFill(['gravityzone_id' => 'client-old', 'name' => 'Nome precedente', 'last_seen_at' => now(), 'is_present' => false])->save();
        $client->update(['notes' => 'Nota locale']);
        $pc = Computer::create(['company_id' => $client->id, 'name' => 'PC locale']);
        $pc->updateCommercial(['expires_on' => '2027-05-01', 'amount' => '75,00'], 0);
        $before = $pc->getAttributes();
        foreach ([true, false, true] as $includeOld) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(fn ($request) => Http::response($this->rpc(match ($request['method']) {
                'getCompaniesList' => array_map(fn ($id) => ['id' => $id, 'name' => 'Nome '.$id], match ($request['params']['parentId'] ?? null) {
                    null => ['partner-a', 'partner-unshared'],
                    'partner-a' => $includeOld ? ['partner-b', 'client-old', 'partner-a'] : ['partner-b', 'partner-a'],
                    'partner-b' => $includeOld ? ['client-new', 'client-old'] : ['client-new'],
                }),
                'getLicenseInfo' => ['subscriptionType' => 3, 'endSubscription' => '2027-06-30'],
                'getCompanyDetails' => ['id' => $request['params']['companyId'], 'isSuspended' => false,
                    'type' => str_starts_with($request['params']['companyId'], 'partner-') ? 0 : 1,
                    'canBeManagedByAbove' => $request['params']['companyId'] !== 'partner-unshared'],
            })));
            $this->assertSame($includeOld ? 5 : 4, app(CompanySynchronizer::class)->sync());
            $this->assertDatabaseCount('companies', 5);
            $this->assertSame($includeOld, $client->refresh()->is_present);
            $this->assertSame('Nota locale', $client->notes);
            $this->assertSame($before, $pc->refresh()->getAttributes());
            Http::assertNotSent(fn ($request): bool => $request['method'] === 'getCompaniesList' &&
                in_array($request['params']['parentId'] ?? null, ['partner-unshared', 'client-old', 'client-new'], true));
        }
    }

    public function test_failed_partner_discovery_preserves_previous_client_presence_and_reports_failure(): void
    {
        $existing = new Company;
        $existing->forceFill(['gravityzone_id' => 'previous-client', 'name' => 'Cliente precedente', 'last_seen_at' => now()])->save();
        Http::fake(fn ($request) => Http::response($request['method'] === 'getCompaniesList' && isset($request['params']['parentId'])
            ? ['id' => $request['id'], 'jsonrpc' => '2.0', 'error' => ['code' => -32602, 'message' => 'private-data']]
            : $this->rpc(match ($request['method']) {
                'getCompaniesList' => [['id' => 'partner-1', 'name' => 'Partner esempio']],
                'getLicenseInfo' => ['subscriptionType' => 3],
                'getCompanyDetails' => ['id' => 'partner-1', 'type' => 0, 'canBeManagedByAbove' => true],
            })));
        $this->artisan('bitlicense:sync-companies')->assertFailed();
        $this->assertTrue($existing->refresh()->is_present);
        $this->assertNotNull(Company::where('gravityzone_id', 'partner-1')->sole()->sync_error);
    }

    private function fakeCompanies(array $companies, array $license = ['subscriptionType' => 2, 'expiryDate' => '2027-06-30T23:30:00'], ?bool $suspended = false): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response($this->rpc(match ($request['method']) {
            'getCompaniesList' => $companies,
            'getCompanyDetails' => ['id' => $request['params']['companyId'], 'isSuspended' => $suspended, 'type' => 1, 'canBeManagedByAbove' => true],
            'getLicenseInfo' => $license,
        })));
    }

    private function rpc(array $result): array
    {
        return ['id' => '103d7b05-ec02-481b-9ed6-c07b97de2b7a', 'jsonrpc' => '2.0', 'result' => $result];
    }
}
