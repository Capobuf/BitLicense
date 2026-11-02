<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Coverage;
use App\Models\Endpoint;
use App\Models\Workstation;
use App\Services\CoverageService;
use App\Services\ExpiryOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpiryOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(new \DateTimeImmutable('2026-11-02 12:00:00 Europe/Rome'));
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
    }

    public function test_company_and_workstation_expiries_remain_separate_for_the_four_april_and_one_may_periods(): void
    {
        $company = $this->company();
        $workstations = collect(range(1, 5))->map(fn (int $number): Workstation => $this->workstation($company, 'PC '.$number));
        foreach ($workstations->take(4) as $workstation) {
            $this->coverage($workstation, '2026-04-20', '2027-04-20');
        }
        $this->coverage($workstations->last(), '2026-05-01', '2027-05-01');

        $rows = app(ExpiryOverview::class)->rows();

        $this->assertCount(6, $rows);
        $this->assertSame('technical', $rows['company-'.$company->id]['type']);
        $this->assertSame('2026-11-20', $rows['company-'.$company->id]['expires_on']);
        $this->assertSame('expiring', $rows['company-'.$company->id]['status']);
        foreach ($workstations as $index => $workstation) {
            $row = $rows['workstation-'.$workstation->id];
            $this->assertSame('commercial', $row['type']);
            $this->assertSame($index < 4 ? '2027-04-20' : '2027-05-01', $row['expires_on']);
            $this->assertSame('active', $row['status']);
        }
    }

    public function test_contiguous_renewals_supersede_old_expiries_even_when_created_out_of_order(): void
    {
        $workstation = $this->workstation($this->company());
        $this->coverage($workstation, '2026-11-11', '2027-11-10');
        $this->coverage($workstation, '2025-11-02', '2026-11-01');
        $this->coverage($workstation, '2026-11-02', '2026-11-10');

        $row = app(ExpiryOverview::class)->rows()['workstation-'.$workstation->id];

        $this->assertSame('2027-11-10', $row['expires_on']);
        $this->assertSame('active', $row['status']);
        $this->assertDatabaseCount('coverages', 3);
        $this->assertDatabaseCount('coverage_assignments', 3);
    }

    public function test_a_later_purchased_period_cannot_hide_a_gap_after_the_current_one(): void
    {
        $workstation = $this->workstation($this->company());
        $this->coverage($workstation, '2026-01-01', '2026-11-10');
        $this->coverage($workstation, '2026-11-12', '2027-11-11');

        $row = app(ExpiryOverview::class)->rows()['workstation-'.$workstation->id];

        $this->assertSame('expiring', $row['status']);
        $this->assertSame('2026-11-10', $row['expires_on']);
    }

    #[DataProvider('pastPeriodOptions')]
    public function test_future_only_periods_and_gaps_today_are_not_misreported_as_active_or_expired(bool $withPast): void
    {
        $workstation = $this->workstation($this->company());
        if ($withPast) {
            $this->coverage($workstation, '2026-01-01', '2026-11-01');
        }
        $this->coverage($workstation, '2026-11-03', '2027-11-02');

        $row = app(ExpiryOverview::class)->rows()['workstation-'.$workstation->id];

        $this->assertSame('gap', $row['status']);
        $this->assertSame('2026-11-03', $row['expires_on']);
        $this->assertStringContainsString('Nessuna copertura commerciale attiva oggi', $row['detail']);
        $this->assertStringContainsString('Prossimo inizio commerciale: 03/11/2026', $row['detail']);
    }

    public static function pastPeriodOptions(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('expiryBoundaries')]
    public function test_today_is_still_covered_and_the_thirty_day_horizon_is_inclusive(string $date, string $status): void
    {
        $company = $this->company(['technical_expires_on' => $date]);
        $workstation = $this->workstation($company);
        $this->coverage($workstation, '2026-01-01', $date);

        $rows = app(ExpiryOverview::class)->rows();

        $this->assertSame($status, $rows['company-'.$company->id]['status']);
        $this->assertSame($status, $rows['workstation-'.$workstation->id]['status']);
        $this->assertSame($date, $rows['workstation-'.$workstation->id]['expires_on']);
    }

    public static function expiryBoundaries(): array
    {
        return [['2026-11-01', 'expired'], ['2026-11-02', 'expiring'], ['2026-12-02', 'expiring'], ['2026-12-03', 'active']];
    }

    public function test_the_business_day_is_rome_even_when_the_utc_date_is_still_yesterday(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-11-01 23:30:00 UTC'));
        $company = $this->company(['technical_expires_on' => '2026-11-01']);
        $workstation = $this->workstation($company);
        $this->coverage($workstation, '2026-11-02', '2026-11-02');

        $rows = app(ExpiryOverview::class)->rows();

        $this->assertSame('expired', $rows['company-'.$company->id]['status']);
        $this->assertSame('expiring', $rows['workstation-'.$workstation->id]['status']);
    }

    public function test_unknown_technical_dates_and_no_commercial_coverage_are_distinct_missing_information(): void
    {
        $company = $this->company(['technical_expires_on' => null, 'technical_license_type' => 'Mensile',
            'is_present' => false, 'sync_error' => 'Lettura licenza non riuscita.']);
        $workstation = $this->workstation($company);

        $rows = app(ExpiryOverview::class)->rows();
        $technical = $rows['company-'.$company->id];
        $commercial = $rows['workstation-'.$workstation->id];

        $this->assertSame('missing', $technical['status']);
        $this->assertNull($technical['expires_on']);
        $this->assertStringContainsString('non disponibile in GravityZone', $technical['detail']);
        $this->assertStringContainsString('ultimo dato disponibile', $technical['detail']);
        $this->assertStringContainsString('Lettura licenza non riuscita.', $technical['detail']);
        $this->assertSame('missing', $commercial['status']);
        $this->assertNull($commercial['expires_on']);
        $this->assertStringContainsString('Nessuna copertura commerciale registrata', $commercial['detail']);
    }

    public function test_transferred_history_does_not_claim_the_source_is_still_covered_or_duplicate_the_destination(): void
    {
        $company = $this->company();
        $source = $this->workstation($company, 'PC sostituito');
        $target = $this->workstation($company, 'PC sostitutivo');
        $coverage = $this->coverage($source, '2026-01-01', '2027-12-31');
        app(CoverageService::class)->transfer($coverage, $target);

        $rows = app(ExpiryOverview::class)->rows();

        $this->assertSame('missing', $rows['workstation-'.$source->id]['status']);
        $this->assertNull($rows['workstation-'.$source->id]['expires_on']);
        $this->assertStringContainsString('trasferite restano nello storico', $rows['workstation-'.$source->id]['detail']);
        $this->assertSame('active', $rows['workstation-'.$target->id]['status']);
        $this->assertSame('2027-12-31', $rows['workstation-'.$target->id]['expires_on']);
        $this->assertDatabaseCount('coverages', 1);
        $this->assertDatabaseCount('coverage_assignments', 2);
    }

    public function test_only_unlinked_absent_mismatched_or_absent_company_endpoints_need_verification(): void
    {
        $company = $this->company();
        $other = $this->company();
        $unlinked = $this->endpoint($company, 'unlinked');
        $absent = $this->endpoint($company, 'absent');
        $this->workstation($company)->linkEndpoint($absent);
        $absent->forceFill(['is_present' => false])->save();
        $mismatched = $this->endpoint($company, 'mismatched');
        $this->workstation($company)->linkEndpoint($mismatched);
        $mismatched->forceFill(['company_id' => $other->id])->save();
        $healthy = $this->endpoint($company, 'healthy');
        $this->workstation($company)->linkEndpoint($healthy);
        $oldCompany = $this->company();
        $old = $this->endpoint($oldCompany, 'old-company');
        $this->workstation($oldCompany)->linkEndpoint($old);
        $oldCompany->forceFill(['is_present' => false])->save();

        $rows = app(ExpiryOverview::class)->rows()->where('type', 'endpoint');

        $this->assertCount(4, $rows);
        $this->assertFalse($rows->has('endpoint-'.$healthy->id));
        foreach ([$unlinked, $absent, $mismatched, $old] as $endpoint) {
            $this->assertSame('verify', $rows['endpoint-'.$endpoint->id]['status']);
            $this->assertNull($rows['endpoint-'.$endpoint->id]['expires_on']);
        }
        $this->assertStringContainsString('associare esplicitamente', $rows['endpoint-'.$unlinked->id]['detail']);
        $this->assertStringContainsString('non restituito', $rows['endpoint-'.$absent->id]['detail']);
        $this->assertStringContainsString('aziende diverse', $rows['endpoint-'.$mismatched->id]['detail']);
        $this->assertStringContainsString('Azienda non restituita', $rows['endpoint-'.$old->id]['detail']);
    }

    public function test_company_filter_and_repeated_reads_use_batched_selects_without_writes_or_messages(): void
    {
        $selected = $this->company();
        $excluded = $this->company();
        foreach ([$selected, $excluded] as $company) {
            foreach (range(1, 3) as $index) {
                $workstation = $this->workstation($company);
                $this->coverage($workstation, '2026-01-01', '2027-12-31');
                $this->endpoint($company, 'endpoint-'.$company->id.'-'.$index);
            }
        }
        DB::enableQueryLog();
        $first = app(ExpiryOverview::class)->rows($selected->id);
        $queries = DB::getQueryLog();
        DB::flushQueryLog();
        $second = app(ExpiryOverview::class)->rows($selected->id);
        $secondQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(7, $first);
        $this->assertSame([$selected->id], $first->pluck('company_id')->unique()->values()->all());
        $this->assertSame($first->all(), $second->all());
        $this->assertSame($first->count(), $first->keys()->unique()->count());
        foreach ($first as $key => $row) {
            $this->assertSame($key, $row['id']);
        }
        $this->assertLessThanOrEqual(5, count($queries));
        foreach ([...$queries, ...$secondQueries] as $query) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $query['query']);
        }
        $this->assertTrue(app(ExpiryOverview::class)->rows(999999)->isEmpty());
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
    }

    private function company(array $changes = []): Company
    {
        $company = new Company;
        $company->forceFill(array_replace([
            'gravityzone_id' => 'company-'.(Company::count() + 1), 'name' => 'Azienda esempio',
            'technical_expires_on' => '2026-11-20', 'last_seen_at' => now(),
        ], $changes))->save();

        return $company;
    }

    private function workstation(Company $company, string $name = 'PC locale'): Workstation
    {
        return Workstation::create(['company_id' => $company->id, 'name' => $name]);
    }

    private function endpoint(Company $company, string $externalId): Endpoint
    {
        $endpoint = new Endpoint;
        $endpoint->forceFill(['company_id' => $company->id, 'gravityzone_id' => $externalId,
            'name' => 'PC-API', 'last_seen_at' => now()])->save();

        return $endpoint;
    }

    private function coverage(Workstation $workstation, string $start, string $end): Coverage
    {
        return app(CoverageService::class)->create([
            'company_id' => $workstation->company_id, 'workstation_ids' => [$workstation->id],
            'starts_on' => $start, 'ends_on' => $end, 'amount' => '79.90',
        ])->sole();
    }
}
