<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneClient;
use App\Models\Company;
use App\Models\CompanyRenewal;
use App\Models\Computer;
use App\Models\ComputerPeriod;
use App\Models\GravityZoneSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

class CommercialCyclesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 4)->setTime(12, 0));
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        Http::preventStrayRequests();
    }

    public function test_renewals_regroup_computers_with_independent_dates_and_keep_every_previous_cycle(): void
    {
        $company = $this->company();
        [$a, $b, $c, $d] = array_map(fn ($name) => $this->computer($company, $name), ['A', 'B', 'C', 'D']);
        $sale = $this->save($company, [$this->row($a), $this->row($b), $this->row($c)]);
        $otherSale = $this->save($company, [$this->row($d)]);
        $renewal = $this->save($company, [
            $this->row($a, '2027-01-01', '2027-12-31'),
            $this->row($b, '2027-01-01', '2027-06-30'),
            $this->row($d, '2027-01-01', '2027-12-31'),
        ], ['kind' => 'renewal', 'occurred_on' => '2027-01-01']);

        $this->assertSame($sale->periods->firstWhere('computer_id', $a->id)->id, $renewal->periods->firstWhere('computer_id', $a->id)->previous_period_id);
        $this->assertSame($otherSale->periods->first()->id, $renewal->periods->firstWhere('computer_id', $d->id)->previous_period_id);
        $this->assertSame('2027-06-30', $renewal->periods->firstWhere('computer_id', $b->id)->expires_on->toDateString());
        $this->assertSame(1, $a->refresh()->renewalCount());
        $this->assertSame(0, $c->refresh()->renewalCount());
        $this->assertSame($sale->id, $a->effectivePeriod()->company_renewal_id);
        $this->assertSame($renewal->id, $a->nextPeriod()->company_renewal_id);
        $this->assertNull($a->getRawOriginal('activated_on'));
        $this->assertNull($a->price_cents);
        $this->assertSame(30000, $sale->price_cents);
        $this->assertSame('2026-12-31', $a->expires_on->toDateString());
        $this->assertSame('2026-12-31', $company->refresh()->technical_expires_on->toDateString());
        $this->assertSame(['R1', 'Primo rinnovo'], [$renewal->reference, $renewal->label]);
        Http::assertNothingSent();
    }

    public function test_effective_dates_and_sql_agree_across_future_start_expiry_and_gaps_without_writing(): void
    {
        $company = $this->company();
        $computer = $this->computer($company);
        $this->save($company, [$this->row($computer)]);
        $this->save($company, [$this->row($computer, '2027-02-01', '2028-02-29')], ['kind' => 'renewal']);
        $this->save($company, [$this->row($computer, '2028-03-01', '2029-03-01')], ['kind' => 'renewal']);
        $before = $computer->refresh()->getAttributes();
        $third = $computer->periods->last();
        $this->assertSame($computer->periods[1]->id, $third->previous_period_id);
        foreach ([
            ['2026-11-04', '2026-12-31', 'Attiva'],
            ['2026-12-31', '2026-12-31', 'In scadenza'],
            ['2027-01-10', '2026-12-31', 'Scaduta'],
            ['2027-02-01', '2028-02-29', 'Attiva'],
            ['2028-03-01', '2029-03-01', 'Attiva'],
        ] as [$date, $expiry, $status]) {
            $this->travelTo(now()->parse($date.' 12:00:00', 'Europe/Rome'));
            $computer->refresh();
            $this->assertSame($expiry, $computer->expires_on->toDateString());
            $this->assertSame($status, $computer->commercialStatus());
            $this->assertSame(1, Computer::whereRaw(Computer::commercialDateSql('expires_on').' = ?', [$expiry])->count());
            $this->assertSame($before, $computer->getAttributes());
        }
    }

    public function test_upgrade_applies_company_wide_preserves_periods_and_does_not_count_as_renewal(): void
    {
        $company = $this->company();
        $a = $this->computer($company, 'A');
        $b = $this->computer($company, 'B');
        $sale = $this->save($company, [$this->row($a), $this->row($b, '2026-03-01', '2027-02-28')]);
        $before = ComputerPeriod::orderBy('id')->get()->toArray();
        $company->forceFill(['technical_product' => 'Premium'])->save();
        $upgrade = $company->saveCommercialOperation($this->upgrade(), expectedComputerVersions: $this->versions($company));

        $this->assertSame(2, $upgrade->quantity);
        $this->assertSame(2500, $upgrade->price_cents);
        $this->assertSame('Base', $upgrade->affected_periods[0]['previous_product']);
        $this->assertSame('2026-12-31', $upgrade->affected_periods[0]['expires_on']);
        $this->assertSame('2027-02-28', $upgrade->affected_periods[1]['expires_on']);
        $this->assertSame($before, ComputerPeriod::orderBy('id')->get()->toArray());
        $this->assertSame('Premium', $a->refresh()->commercialProduct());
        $this->assertSame(0, $a->renewalCount());
        $this->assertSame('Base', $sale->periods->first()->product);
        $new = $this->computer($company, 'Nuovo');
        $this->save($company, [$this->row($new, '2026-11-01', '2027-10-31')]);
        $this->assertSame('Premium', $new->refresh()->commercialProduct());
        $updated = $company->saveCommercialOperation(array_replace($this->upgrade(), ['invoice_reference' => 'F-42']), $upgrade->id, 0, $this->versions($company));
        $this->assertSame('F-42', $updated->invoice_reference);
        $this->assertSame($upgrade->affected_periods, $updated->affected_periods);
        $this->assertCount(1, $updated->history);
    }

    public function test_later_renewal_captures_assigned_license_and_next_upgrade_snapshots_it(): void
    {
        $company = $this->company();
        $a = $this->computer($company);
        $this->save($company, [$this->row($a)]);
        $company->forceFill(['technical_product' => 'Premium'])->save();
        $company->saveCommercialOperation($this->upgrade(), expectedComputerVersions: $this->versions($company));
        $company->forceFill(['technical_product' => 'Ultimate'])->save();
        $this->save($company, [$this->row($a, '2027-01-01', '2027-12-31')], ['kind' => 'renewal', 'occurred_on' => '2027-01-01']);
        $this->assertSame('Premium', $a->refresh()->commercialProduct());
        $this->travelTo(now()->parse('2027-02-01 12:00:00', 'Europe/Rome'));
        $this->assertSame('Ultimate', $a->refresh()->commercialProduct());
        $company->forceFill(['technical_product' => 'Enterprise'])->save();
        $upgrade = $company->saveCommercialOperation(array_replace($this->upgrade(), ['occurred_on' => '2027-02-01']), expectedComputerVersions: $this->versions($company));
        $this->assertSame('Ultimate', $upgrade->affected_periods[0]['previous_product']);
        $this->assertSame('Enterprise', $a->refresh()->commercialProduct());
    }

    public function test_upgrade_of_an_existing_period_wins_over_its_later_billing_date_and_preserves_product_snapshots(): void
    {
        $company = $this->company();
        $computer = $this->computer($company);
        $this->save($company, [$this->row($computer)], ['occurred_on' => '2026-05-20']);
        $company->forceFill(['technical_product' => 'Premium'])->save();
        $first = $company->saveCommercialOperation(array_replace($this->upgrade(), ['occurred_on' => '2026-05-01']), expectedComputerVersions: $this->versions($company));
        $this->assertSame('Base', $first->affected_periods[0]['previous_product']);
        $this->assertSame('Premium', $computer->refresh()->commercialProduct());
        $company->forceFill(['technical_product' => 'Enterprise'])->save();
        $second = $company->saveCommercialOperation(array_replace($this->upgrade(), ['occurred_on' => '2026-05-10']), expectedComputerVersions: $this->versions($company));
        $this->assertSame('Premium', $second->affected_periods[0]['previous_product']);
        $this->assertSame('Enterprise', $computer->refresh()->commercialProduct());
        $this->travelTo(now()->parse('2026-04-30 12:00:00', 'Europe/Rome'));
        $this->assertSame('Base', $computer->refresh()->commercialProduct());
        $this->travelTo(now()->parse('2026-05-01 12:00:00', 'Europe/Rome'));
        $this->assertSame('Premium', $computer->refresh()->commercialProduct());
    }

    public function test_upgrade_rejects_unlinked_active_periods_stale_company_membership_and_later_product_history(): void
    {
        $company = $this->company();
        $a = $this->computer($company);
        $this->save($company, [$this->row($a)]);
        $versions = $this->versions($company);
        $unlinked = $this->computer($company, 'Da collegare');
        $this->assertRejected(fn () => $company->saveCommercialOperation($this->upgrade(), expectedComputerVersions: $versions));
        $unlinked->updateCommercial(['activated_on' => '2026-01-01', 'expires_on' => '2026-12-31'], 0);
        $this->assertRejected(fn () => $company->saveCommercialOperation($this->upgrade(), expectedComputerVersions: $this->versions($company)));
        $this->save($company, [$this->row($unlinked)]);
        $first = $company->saveCommercialOperation($this->upgrade(), expectedComputerVersions: $this->versions($company));
        $company->forceFill(['technical_product' => 'Ultimate'])->save();
        $company->saveCommercialOperation(array_replace($this->upgrade(), ['occurred_on' => '2026-11-01']), expectedComputerVersions: $this->versions($company));
        $this->assertRejected(fn () => $company->saveCommercialOperation(array_replace($this->upgrade(), ['occurred_on' => '2026-09-01']), $first->id, 0, $this->versions($company)));
        $this->assertSame('Ultimate', $a->refresh()->commercialProduct());
    }

    public function test_operation_correction_adds_computers_but_cannot_remove_overlap_or_bypass_lineage(): void
    {
        $company = $this->company();
        $a = $this->computer($company, 'A');
        $b = $this->computer($company, 'B');
        $data = $this->data([$this->row($a)], ['quantity' => 2]);
        $sale = $company->saveCommercialOperation($data, expectedComputerVersions: $this->versions($company));
        $expanded = array_replace($data, ['periods' => [$this->row($a), $this->row($b)]]);
        $sale = $company->saveCommercialOperation($expanded, $sale->id, 0, $this->versions($company));
        $this->assertCount(2, $sale->periods);
        $this->assertRejected(fn () => $company->saveCommercialOperation($data, $sale->id, 1, $this->versions($company)));
        $this->assertRejected(fn () => $this->save($company, [$this->row($a, '2026-12-31', '2027-12-31')], ['kind' => 'renewal']));
        $this->save($company, [$this->row($a, '2027-01-01', '2027-12-31')], ['kind' => 'renewal']);
        $invalid = array_replace($expanded, ['periods' => [$this->row($a, '2028-01-01', '2028-12-31'), $this->row($b)]]);
        $this->assertRejected(fn () => $company->saveCommercialOperation($invalid, $sale->id, 1, $this->versions($company)));
        $corrected = array_replace($expanded, ['periods' => [$this->row($a, '2026-01-01', '2026-12-30'), $this->row($b)]]);
        $sale = $company->saveCommercialOperation($corrected, $sale->id, 1, $this->versions($company));
        $this->assertSame('2026-12-30', $a->refresh()->expires_on->toDateString());
        $this->assertCount(2, $sale->history);
    }

    public function test_linking_archives_unlinked_values_and_requires_explicit_current_period_before_future_renewal(): void
    {
        $company = $this->company();
        $a = $this->computer($company);
        $a->updateCommercial(['activated_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'amount' => '49,99', 'product' => 'Annotazione', 'notes' => 'Conserva'], 0);
        $this->assertRejected(fn () => $this->save($company, [$this->row($a, '2027-01-01', '2027-12-31')], ['kind' => 'renewal']));
        $sale = $this->save($company, [$this->row($a)]);
        $a->refresh();
        $this->assertNull($a->price_cents);
        $this->assertSame('linked', $a->history[0]['event']);
        $this->assertSame(4999, $a->history[0]['previous']['price_cents']);
        $this->assertSame('Annotazione', $a->history[0]['previous']['product']);
        $this->assertSame('Conserva', $a->notes);
        $this->assertRejected(fn () => $a->updateCommercial(['expires_on' => '2030-01-01'], $a->commercial_version));
        $a->updateCommercial(['notes' => 'Modificata'], $a->commercial_version);
        $this->assertSame('Modificata', $a->notes);
        $this->assertRejected(fn () => $company->saveLicensePeriod(['kind' => 'initial', 'starts_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'quantity' => 1], $sale->id, 0));
    }

    public function test_replacement_preserves_original_operation_membership_and_future_renewal_lineage(): void
    {
        $company = $this->company();
        $source = $this->computer($company, 'Originale');
        $target = $this->computer($company, 'Sostituto');
        $sale = $this->save($company, [$this->row($source)]);
        $renewal = $this->save($company, [$this->row($source, '2027-01-01', '2027-12-31')], ['kind' => 'renewal']);
        $source->refresh()->transferTo($target, $source->commercial_version);
        $this->assertNull($source->expires_on);
        $this->assertFalse($source->canInitializeActivationFromFirstSeen('2026-01-01'));
        $this->assertSame('2026-12-31', $target->expires_on->toDateString());
        $this->assertSame(1, $target->renewalCount());
        $this->assertSame($target->id, $sale->periods()->first()->computer_id);
        $this->assertSame($source->id, $sale->periods()->first()->origin_computer_id);
        $this->assertSame($sale->periods()->first()->id, $renewal->periods()->first()->previous_period_id);
        $this->assertSame(2, $source->originPeriods()->count());
    }

    public function test_reconciliation_and_technical_sync_preserve_linked_periods_billing_and_commercial_version(): void
    {
        $company = $this->company();
        $local = $this->computer($company);
        $this->save($company, [$this->row($local)]);
        $remote = new Computer;
        $remote->forceFill(['company_id' => $company->id, 'gravityzone_company_id' => $company->id, 'gravityzone_id' => 'remote-id', 'technical_name' => 'PC remoto', 'is_present' => true])->save();
        $local->refresh()->reconcileWith($remote, $local->commercial_version);
        $periods = ComputerPeriod::all()->toArray();
        $operations = CompanyRenewal::all()->toArray();
        $version = $local->commercial_version;
        GravityZoneSetting::create(['id' => 1, 'api_url' => 'https://gravityzone.example.test/api', 'api_key' => 'fake-test-key']);
        $this->mock(GravityZoneClient::class, function (MockInterface $mock): void {
            // Shape already exercised by EndpointSynchronizationTest; no live request.
            $mock->shouldReceive('getNetworkInventoryItems')->andReturnUsing(fn ($settings, array $companies) => array_map(
                fn () => [['id' => 'remote-id', 'name' => 'Rinominato', 'platform' => null, 'is_managed' => null, 'is_isolated' => null, 'first_seen_on' => '2025-06-01']], $companies,
            ));
        });
        app(EndpointSynchronizer::class)->sync();
        app(EndpointSynchronizer::class)->sync();
        $this->assertSame($periods, ComputerPeriod::all()->toArray());
        $this->assertSame($operations, CompanyRenewal::all()->toArray());
        $this->assertSame($version, $local->refresh()->commercial_version);
        $this->assertSame('2026-01-01', $local->activated_on->toDateString());
        $this->assertSame('Rinominato', $local->display_name);
        Http::assertNothingSent();
    }

    public function test_validation_stale_versions_cross_company_and_authorization_reject_without_partial_writes(): void
    {
        $company = $this->company();
        $a = $this->computer($company);
        $foreign = $this->computer($this->company());
        foreach ([
            ['periods' => []], ['quantity' => 0], ['amount' => '1.001'], ['amount' => '-1'], ['product' => 'Forged'],
            ['occurred_on' => '2027-02-29'], ['billing_status' => 'invoiced'], ['billing_status' => 'other'],
            ['periods' => [$this->row($a), $this->row($a)]], ['periods' => [$this->row($foreign)]],
            ['periods' => [$this->row($a, '2027-02-29', '2028-02-29')]], ['company_id' => $company->id],
        ] as $invalid) {
            $this->assertRejected(fn () => $this->save($company, [$this->row($a)], $invalid));
            $this->assertDatabaseCount('company_renewals', 0);
            $this->assertDatabaseCount('computer_periods', 0);
        }
        $versions = $this->versions($company);
        $a->updateCommercial(['notes' => 'Concorrente'], 0);
        $this->assertRejected(fn () => $company->saveCommercialOperation($this->data([$this->row($a)]), expectedComputerVersions: $versions));
        $sale = $this->save($company, [$this->row($a)]);
        $this->assertRejected(fn () => $company->saveCommercialOperation($this->data([$this->row($a)]), $sale->id, 9, $this->versions($company)));
        $this->assertRejected(fn () => $company->saveCommercialOperation($this->data([$this->row($a)]), $sale->id, null, $this->versions($company)));
        $this->actingAs(User::factory()->create(['role' => 'operator', 'is_active' => false]));
        $this->expectException(AuthorizationException::class);
        $this->save($company, [$this->row($a)]);
    }

    public function test_legacy_operation_can_be_explicitly_linked_without_duplicate_sales_or_invented_billing(): void
    {
        $company = $this->company();
        $computer = $this->computer($company);
        $legacy = $company->renewals()->make();
        $legacy->forceFill(['kind' => 'initial', 'starts_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'quantity' => 8])->save();
        $legacy->refresh();
        $promoted = $company->saveCommercialOperation($this->data([$this->row($computer)], ['quantity' => 8]), $legacy->id, 0, $this->versions($company));
        $this->assertSame($legacy->id, $promoted->id);
        $this->assertNull($promoted->billing_status);
        $this->assertSame(1, $promoted->version);
        $this->assertNull($promoted->history[0]['previous']['billing_status']);
        $this->assertDatabaseCount('company_renewals', 1);
        $this->assertDatabaseCount('computer_periods', 1);
    }

    public function test_period_corrections_cannot_retroactively_change_an_upgrade_scope(): void
    {
        $company = $this->company();
        $a = $this->computer($company, 'A');
        $b = $this->computer($company, 'B');
        $this->save($company, [$this->row($a)]);
        $sale = $this->save($company, [$this->row($b, '2026-01-01', '2026-09-30')]);
        $company->forceFill(['technical_product' => 'Premium'])->save();
        $company->saveCommercialOperation($this->upgrade(), expectedComputerVersions: $this->versions($company));
        $this->assertRejected(fn () => $company->saveCommercialOperation($this->data([$this->row($b)]), $sale->id, 0, $this->versions($company)));
        $this->assertSame('2026-09-30', $b->refresh()->expires_on->toDateString());
    }

    public function test_storage_failure_rolls_back_operation_periods_and_archived_commercial_data(): void
    {
        $company = $this->company();
        $a = $this->computer($company);
        $a->updateCommercial(['activated_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'amount' => '50'], 0);
        $before = $a->getAttributes();
        DB::unprepared("CREATE TRIGGER reject_computer BEFORE UPDATE ON computers WHEN NEW.id = {$a->id} BEGIN SELECT RAISE(ABORT, 'simulated-storage-failure'); END;");
        try {
            $this->save($company, [$this->row($a)]);
            $this->fail('Era atteso un errore del database.');
        } catch (QueryException) {
            $this->assertSame($before, $a->refresh()->getAttributes());
            $this->assertDatabaseCount('company_renewals', 0);
            $this->assertDatabaseCount('computer_periods', 0);
        }
    }

    public function test_assigned_licenses_are_captured_per_pc_and_reference_corrections_preserve_them(): void
    {
        $company = $this->company();
        $detectedCompany = $this->company();
        $detectedCompany->forceFill(['technical_product' => 'Remote license'])->save();
        $manual = $this->computer($company);
        $remote = Computer::forceCreate(['company_id' => $company->id, 'gravityzone_company_id' => $detectedCompany->id,
            'gravityzone_id' => 'moved-pc', 'technical_name' => 'PC remoto']);
        $rows = [$this->row($manual), $this->row($remote)];
        $sale = $this->save($company, $rows);
        $this->assertSame(['Base', 'Remote license'], $sale->periods->pluck('product')->all());
        $company->forceFill(['technical_product' => 'Premium'])->save();
        $detectedCompany->forceFill(['technical_product' => null])->save();
        $upgrade = $company->saveCommercialOperation($this->upgrade(), expectedComputerVersions: $this->versions($company));
        $this->assertSame(['Premium', null], array_column($upgrade->affected_periods, 'product'));
        $this->assertSame(['Base', 'Remote license'], array_column($upgrade->affected_periods, 'previous_product'));
        $this->assertNull($remote->fresh()->commercialProduct());

        $company->forceFill(['technical_product' => 'Changed after registration'])->save();
        $detectedCompany->forceFill(['technical_product' => 'Also changed'])->save();
        $corrected = $company->saveCommercialOperation($this->data($rows, ['invoice_reference' => 'F-1']), $sale->id, 0, $this->versions($company));
        $this->assertSame(['Base', 'Remote license'], $corrected->periods->pluck('product')->all());
        $correctedUpgrade = $company->saveCommercialOperation(array_replace($this->upgrade(), ['invoice_reference' => 'F-2']), $upgrade->id, 0, $this->versions($company));
        $this->assertSame($upgrade->affected_periods, $correctedUpgrade->affected_periods);
        $this->assertSame('Premium', $manual->fresh()->commercialProduct());
        $this->assertSame('Changed after registration', $manual->fresh()->company_product);
        Http::assertNothingSent();
    }

    public function test_unknown_assigned_license_does_not_require_manual_product_for_sale_or_upgrade(): void
    {
        $company = $this->company();
        $company->forceFill(['technical_product' => null])->save();
        $computer = $this->computer($company);
        $sale = $this->save($company, [$this->row($computer)]);
        $upgrade = $company->saveCommercialOperation($this->upgrade(), expectedComputerVersions: $this->versions($company));
        $this->assertNull($sale->periods->sole()->product);
        $this->assertNull($upgrade->affected_periods[0]['product']);
        $this->assertNull($upgrade->affected_periods[0]['previous_product']);
        $this->view('filament.commercial-operation', ['operation' => $upgrade])->assertSee('Non disponibile');
        Http::assertNothingSent();
    }

    public function test_company_renews_eight_licenses_and_later_associates_six_of_twelve_computers(): void
    {
        $company = $this->company();
        $pcs = collect(range(1, 12))->map(fn ($number) => $this->computer($company, 'PC '.$number));
        $sale = $this->save($company, $pcs->map(fn ($pc) => $this->row($pc))->all());
        $before = $company->computers()->get()->toArray();
        $data = $this->data([], ['kind' => 'renewal', 'quantity' => 8, 'starts_on' => '2027-01-01', 'expires_on' => '2027-12-31']);
        $renewal = $company->saveCommercialOperation($data);
        $this->assertSame(8, $renewal->quantity);
        $this->assertCount(0, $renewal->periods);
        $this->assertSame($before, $company->computers()->get()->toArray());

        $data['periods'] = $pcs->take(6)->map(fn ($pc) => $this->row($pc, '2027-01-01', '2027-12-31'))->all();
        $data['periods'][0]['expires_on'] = '2027-11-30';
        $renewal = $company->saveCommercialOperation($data, $renewal->id, 0, $this->versions($company));
        $this->assertSame(8, $renewal->quantity);
        $this->assertCount(6, $renewal->periods);
        $this->assertSame('2027-12-31', $renewal->expires_on->toDateString());
        $this->assertSame('2027-11-30', $renewal->periods->first()->expires_on->toDateString());
        foreach ($pcs as $index => $pc) {
            $pc->refresh();
            $this->assertSame($index < 6 ? 1 : 0, $pc->renewalCount());
            $this->assertSame($sale->id, $pc->effectivePeriod()->company_renewal_id);
            $this->assertSame($index < 6 ? $renewal->id : null, $pc->nextPeriod()?->company_renewal_id);
        }
        $data['periods'] = $pcs->take(9)->map(fn ($pc) => $this->row($pc, '2027-01-01', '2027-12-31'))->all();
        $this->assertRejected(fn () => $company->saveCommercialOperation($data, $renewal->id, 1, $this->versions($company)));
        $this->assertCount(6, $renewal->fresh()->periods);
        $this->assertSame('2027-11-30', $renewal->fresh()->periods->first()->expires_on->toDateString());
        Http::assertNothingSent();
    }

    public function test_company_renewal_without_computers_requires_valid_dates_and_positive_quantity(): void
    {
        $company = $this->company();
        foreach ([
            [], ['starts_on' => '2027-01-01'], ['expires_on' => '2027-12-31'],
            ['starts_on' => '2027-02-29', 'expires_on' => '2027-12-31'],
            ['starts_on' => '2027-01-01', 'expires_on' => '2026-12-31'],
            ['starts_on' => '2027-01-01', 'expires_on' => '2027-12-31', 'quantity' => 0],
        ] as $invalid) {
            $this->assertRejected(fn () => $company->saveCommercialOperation($this->data([], array_replace(['kind' => 'renewal', 'quantity' => 8], $invalid))));
            $this->assertDatabaseCount('company_renewals', 0);
        }
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-'.Company::count(), 'name' => 'Azienda esempio', 'technical_expires_on' => '2026-12-31', 'technical_product' => 'Base', 'is_present' => true, 'last_seen_at' => now()])->save();

        return $company;
    }

    private function computer(Company $company, string $name = 'PC esempio'): Computer
    {
        return Computer::create(['company_id' => $company->id, 'name' => $name])->refresh();
    }

    private function row(Computer $computer, string $start = '2026-01-01', string $end = '2026-12-31'): array
    {
        return ['computer_id' => $computer->id, 'starts_on' => $start, 'expires_on' => $end];
    }

    private function data(array $rows, array $overrides = []): array
    {
        return array_replace(['kind' => 'initial', 'occurred_on' => '2026-01-01', 'amount' => '300,00', 'quantity' => count($rows), 'periods' => $rows], $overrides);
    }

    private function upgrade(): array
    {
        return ['kind' => 'upgrade', 'occurred_on' => '2026-10-01', 'amount' => '25,00'];
    }

    private function versions(Company $company): array
    {
        return $company->computers()->pluck('commercial_version', 'id')->all();
    }

    private function save(Company $company, array $rows, array $overrides = []): CompanyRenewal
    {
        return $company->saveCommercialOperation($this->data($rows, $overrides), expectedComputerVersions: $this->versions($company));
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
