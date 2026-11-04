<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneClient;
use App\Models\Company;
use App\Models\Computer;
use App\Models\GravityZoneSetting;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class ComputerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dates_are_directly_editable_independent_per_computer_and_history_is_automatic(): void
    {
        $company = $this->company();
        $company->forceFill(['technical_expires_on' => '2026-12-31'])->save();
        $first = $this->computer($company);
        $fifth = $this->computer($company);
        $first->updateCommercial(['activated_on' => '2026-04-20', 'expires_on' => '2027-04-20'], 0);
        $fifth->updateCommercial(['activated_on' => '2026-05-01', 'expires_on' => '2027-05-01', 'amount' => '123,45'], 0);
        $this->assertNull($first->history);
        $this->assertNull($first->product);
        $this->assertNull($first->price_cents);
        $this->assertSame(12345, $fifth->price_cents);
        $this->assertSame('2026-12-31', $company->refresh()->technical_expires_on->toDateString());
        $this->assertSame('2027-04-20', $first->expires_on->toDateString());
        $fifth->updateCommercial(['expires_on' => '2028-05-01'], 1);
        $this->assertSame('2027-05-01', $fifth->history[0]['previous']['expires_on']);
        $this->assertSame(12345, $fifth->history[0]['previous']['price_cents']);
        $this->assertSame(12345, $fifth->price_cents);
        $fifth->updateCommercial(['expires_on' => '2028-05-01'], 2);
        $this->assertSame(2, $fifth->commercial_version);
        $this->assertCount(1, $fifth->history);
        $fifth->updateCommercial(['notes' => 'Solo una nota'], 2);
        $this->assertCount(1, $fifth->history);
    }

    public function test_optional_dates_and_amount_can_be_cleared_and_zero_is_preserved(): void
    {
        $computer = $this->computer($this->company());
        $computer->updateCommercial(['expires_on' => '2028-02-29', 'amount' => '0'], 0);
        $this->assertNull($computer->activated_on);
        $this->assertSame(0, $computer->price_cents);
        $computer->updateCommercial(['activated_on' => '2028-02-29', 'expires_on' => '2028-02-29'], 1);
        $computer->updateCommercial(['activated_on' => '', 'expires_on' => '', 'amount' => ''], 2);
        $this->assertNull($computer->activated_on);
        $this->assertNull($computer->expires_on);
        $this->assertNull($computer->price_cents);
    }

    public function test_invalid_dates_money_remote_fields_and_stale_edits_are_rejected_without_changes(): void
    {
        $computer = $this->computer($this->company());
        $computer->updateCommercial(['activated_on' => '2026-04-20', 'expires_on' => '2027-04-20'], 0);
        $before = $computer->toArray();
        foreach ([
            ['expires_on' => '2026-04-19'], ['activated_on' => '2027-04-21'],
            ['activated_on' => '2027-02-29'], ['expires_on' => '2026-13-01'],
            ['amount' => '-1'], ['amount' => '1.001'], ['amount' => '1e3'], ['amount' => '1.000,00'],
            ['amount' => 2.50], ['gravityzone_id' => 'forged'], ['company_id' => 2],
            ['gravityzone_license_status' => 1], ['license_checked_at' => '2026-11-04 10:00:00'], ['license_sync_error' => null],
        ] as $input) {
            $this->assertRejected(fn () => $computer->updateCommercial($input, 1));
            $this->assertSame($before, $computer->refresh()->toArray());
        }
        $this->assertRejected(fn () => $computer->updateCommercial(['notes' => 'Stale'], 0));
        $this->assertSame($before, $computer->refresh()->toArray());
    }

    public function test_manual_name_is_required_but_remote_name_cannot_be_edited(): void
    {
        $company = $this->company();
        $this->assertRejected(fn () => Computer::create(['company_id' => $company->id]));
        $computer = $this->remote($company);
        $this->assertSame('PC-REMOTO', $computer->display_name);
        $this->assertRejected(fn () => $computer->updateCommercial(['name' => 'Nome alterato'], 0));
        $this->assertRejected(fn () => $computer->updateCommercial(['technical_name' => 'Nome alterato'], 0));
        $this->assertSame('PC-REMOTO', $computer->refresh()->display_name);
        $other = $this->company('other');
        $this->assertRejected(fn () => $computer->forceFill(['company_id' => $other->id])->save());
    }

    public function test_replacement_transfers_paid_fields_without_duplicating_them_and_preserves_names_notes_and_history(): void
    {
        $company = $this->company();
        $source = $this->computer($company, 'Originale');
        $target = $this->computer($company, 'Sostituto');
        $source->updateCommercial(['activated_on' => '2026-05-01', 'expires_on' => '2027-05-01', 'product' => 'Antivirus', 'amount' => '90,50', 'invoice_reference' => 'F-1', 'quote_reference' => 'P-1', 'commercial_description' => 'Descrizione', 'notes' => 'Nota vecchio PC'], 0);
        $target->updateCommercial(['notes' => 'Nota nuovo PC'], 0);
        $source->transferTo($target, 1);
        $this->assertNull($source->activated_on);
        $this->assertNull($source->expires_on);
        $this->assertNull($source->price_cents);
        $this->assertNull($source->product);
        $this->assertSame('Nota vecchio PC', $source->notes);
        $this->assertSame('2027-05-01', $target->expires_on->toDateString());
        $this->assertSame(9050, $target->price_cents);
        $this->assertSame('Antivirus', $target->product);
        $this->assertSame('F-1', $target->invoice_reference);
        $this->assertSame('P-1', $target->quote_reference);
        $this->assertSame('Sostituto', $target->display_name);
        $this->assertSame('Nota nuovo PC', $target->notes);
        $this->assertSame('Sostituto', $source->history[0]['related_computer_name']);
        $this->assertSame('2027-05-01', $source->history[0]['previous']['expires_on']);
        $this->assertSame('transferred_in', $target->history[0]['event']);
        $this->assertRejected(fn () => $source->transferTo($target, 1));
        $this->assertRejected(fn () => $source->transferTo($this->computer($company), 2));
        $this->assertRejected(fn () => $source->updateCommercial(['expires_on' => '2027-05-01'], 1));
        $this->assertNull($source->refresh()->expires_on);
        $this->assertCount(1, $target->refresh()->history);
    }

    public function test_transfer_rejects_wrong_company_same_computer_and_commercial_target_without_overwriting(): void
    {
        $company = $this->company();
        $source = $this->computer($company);
        $source->updateCommercial(['activated_on' => '2026-05-01', 'expires_on' => '2027-05-01'], 0);
        $this->assertRejected(fn () => $source->transferTo($source, 1));
        $this->assertRejected(fn () => $source->transferTo($this->computer($this->company('other')), 1));
        foreach ([['product' => 'Esistente'], ['amount' => '0'], ['invoice_reference' => 'F'], ['expires_on' => '2028-01-01']] as $data) {
            $target = $this->computer($company);
            $target->updateCommercial($data, 0);
            $before = $target->toArray();
            $this->assertRejected(fn () => $source->transferTo($target, 1));
            $this->assertSame($before, $target->refresh()->toArray());
        }
        $this->assertSame('2027-05-01', $source->refresh()->expires_on->toDateString());
    }

    public function test_contextual_reconciliation_keeps_local_data_and_next_sync_cannot_recreate_duplicate(): void
    {
        Http::preventStrayRequests();
        $company = $this->company();
        $local = $this->computer($company, 'Nome manuale');
        $local->updateCommercial(['activated_on' => '2026-05-01', 'expires_on' => '2027-05-01', 'notes' => 'Da conservare'], 0);
        $remote = $this->remote($company);
        $local->reconcileWith($remote, 1);
        $this->assertDatabaseCount('computers', 1);
        $this->assertSame('remote-id', $local->gravityzone_id);
        $this->assertSame('PC-REMOTO', $local->display_name);
        $this->assertSame('Nome manuale', $local->name);
        $this->assertSame('2027-05-01', $local->expires_on->toDateString());
        $this->assertSame('Da conservare', $local->notes);
        $this->assertSame('reconciled', $local->history[0]['event']);
        $this->assertRejected(fn () => $local->reconcileWith($remote, 1));
        GravityZoneSetting::create(['id' => 1, 'api_url' => 'https://gravityzone.example.test/api', 'api_key' => 'fake-test-key']);
        $this->mock(GravityZoneClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getNetworkInventoryItems')->andReturn([['id' => 'remote-id', 'name' => 'RINOMINATO', 'platform' => null, 'is_managed' => null, 'is_isolated' => null]]);
        });
        app(EndpointSynchronizer::class)->sync();
        $this->assertDatabaseCount('computers', 1);
        $this->assertSame('RINOMINATO', $local->refresh()->display_name);
        $this->assertSame('Da conservare', $local->notes);
        $this->assertSame('2027-05-01', $local->expires_on->toDateString());
        Http::assertNothingSent();
    }

    public function test_reconciliation_rejects_conflicting_remote_data_absence_and_company_mismatch(): void
    {
        $company = $this->company();
        $local = $this->computer($company);
        $remote = $this->remote($company);
        foreach ([['notes' => 'Non perdere'], ['product' => 'Prodotto'], ['price_cents' => 0], ['history' => [['event' => 'updated']]], ['is_present' => false], ['gravityzone_company_id' => $this->company('other')->id]] as $attributes) {
            $before = $remote->getAttributes();
            $remote->forceFill($attributes)->save();
            $this->assertRejected(fn () => $local->reconcileWith($remote, 0));
            $remote->setRawAttributes($before)->save();
        }
        $this->assertDatabaseCount('computers', 2);
        $this->assertNull($local->refresh()->gravityzone_id);
        $this->assertRejected(fn () => $local->reconcileWith($remote, 10));
        $company->forceFill(['is_present' => false])->save();
        $this->assertRejected(fn () => $local->reconcileWith($remote, 0));
    }

    public function test_database_failures_roll_back_transfer_and_reconciliation_without_losing_the_source(): void
    {
        $company = $this->company();
        $source = $this->computer($company, 'Originale');
        $source->updateCommercial(['activated_on' => '2026-05-01', 'expires_on' => '2027-05-01'], 0);
        $target = $this->computer($company, 'Sostituto');
        $remote = $this->remote($company);
        $before = Computer::orderBy('id')->get()->toArray();
        DB::unprepared("CREATE TRIGGER reject_target BEFORE UPDATE ON computers WHEN NEW.id = {$target->id} BEGIN SELECT RAISE(ABORT, 'simulated-storage-failure'); END;");
        foreach ([fn () => $source->transferTo($target, 1), fn () => $target->reconcileWith($remote, 0)] as $operation) {
            try {
                $operation();
                $this->fail('Era atteso un errore del database.');
            } catch (QueryException) {
                $this->assertSame($before, Computer::orderBy('id')->get()->toArray());
            }
        }
    }

    public function test_computer_policy_allows_only_active_admins_and_operators_and_disallows_deletion(): void
    {
        $computer = $this->computer($this->company());
        foreach ([['admin', true, true], ['operator', true, true], ['admin', false, false], ['unknown', true, false]] as [$role, $active, $allowed]) {
            $user = User::factory()->make(['role' => $role, 'is_active' => $active]);
            $this->assertSame($allowed, Gate::forUser($user)->allows('viewAny', Computer::class));
            $this->assertSame($allowed, Gate::forUser($user)->allows('create', Computer::class));
            $this->assertSame($allowed, Gate::forUser($user)->allows('update', $computer));
            $this->assertFalse(Gate::forUser($user)->allows('delete', $computer));
        }
    }

    private function company(string $id = 'company-1'): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => $id, 'name' => 'Azienda esempio', 'last_seen_at' => now()])->save();

        return $company;
    }

    private function computer(Company $company, string $name = 'PC manuale'): Computer
    {
        return Computer::create(['company_id' => $company->id, 'name' => $name])->refresh();
    }

    private function remote(Company $company): Computer
    {
        $computer = new Computer;
        $computer->forceFill(['company_id' => $company->id, 'gravityzone_company_id' => $company->id, 'gravityzone_id' => 'remote-id', 'technical_name' => 'PC-REMOTO', 'is_present' => true, 'last_seen_at' => now()])->save();

        return $computer->refresh();
    }

    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Era atteso il rifiuto dell’operazione.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
