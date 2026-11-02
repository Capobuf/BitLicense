<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\CompanySynchronizer;
use App\Models\Company;
use App\Models\GravityZoneSetting;
use Illuminate\Console\Scheduling\Schedule;
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
        $company->update(['contact_name' => 'Referente locale', 'contact_email' => 'contatto@example.test', 'contact_phone' => '+390000000', 'notes' => 'Nota commerciale locale']);

        $this->travel(1)->hours();
        $this->fakeCompanies([['id' => 'company-1', 'name' => 'Nome remoto aggiornato']], ['subscriptionType' => 3]);
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
        $this->assertNull($company->technical_expires_on);
        $this->assertNull($company->sync_error);
        $this->assertTrue($company->last_seen_at->equalTo(now()));
        $this->assertTrue($company->license_synced_at->equalTo(now()));
        $this->assertTrue(GravityZoneSetting::find(1)->companies_synced_at->equalTo(now()));
        $this->assertNull(GravityZoneSetting::find(1)->companies_sync_error);
        Sleep::assertSleptTimes(2);
        Http::assertSentCount(2);
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
                : Http::response($this->rpc(['subscriptionType' => 3]))));

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
        $this->assertStringContainsString('Aziende con licenza non aggiornata: 1', GravityZoneSetting::find(1)->companies_sync_error);
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
        $this->assertStringContainsString('Aziende con licenza non aggiornata: 2', GravityZoneSetting::find(1)->companies_sync_error);
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

    public function test_the_cli_and_daily_scheduler_use_the_same_synchronizer(): void
    {
        $this->fakeCompanies([]);
        $this->artisan('bitlicense:sync-companies')->assertSuccessful();
        $event = collect(app(Schedule::class)->events())->sole(fn ($event) => str_contains($event->command ?? '', 'bitlicense:sync-companies'));
        $this->assertSame('0 3 * * *', $event->expression);
        $this->assertSame('Europe/Rome', $event->timezone);
        Http::assertSentCount(1);
    }

    private function fakeCompanies(array $companies, array $license = ['subscriptionType' => 2, 'expiryDate' => '2027-06-30T23:30:00']): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn ($request) => Http::response($this->rpc($request['method'] === 'getCompaniesList' ? $companies : $license)));
    }

    private function rpc(array $result): array
    {
        return ['id' => '103d7b05-ec02-481b-9ed6-c07b97de2b7a', 'jsonrpc' => '2.0', 'result' => $result];
    }
}
