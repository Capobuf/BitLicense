<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\CompanySynchronizer;
use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneClient;
use App\Models\Company;
use App\Models\Coverage;
use App\Models\CoverageAssignment;
use App\Models\Endpoint;
use App\Models\GravityZoneSetting;
use App\Models\Workstation;
use App\Services\CoverageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoverageServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_four_april_coverages_and_a_fifth_may_coverage_remain_distinct_under_one_technical_expiry(): void
    {
        $company = $this->company();
        $workstations = collect(range(1, 5))->map(fn (int $number): Workstation => $this->workstation($company, 'PC '.$number));

        $firstFour = app(CoverageService::class)->create($this->input($workstations->take(4)->all()));
        $fifth = app(CoverageService::class)->create($this->input([$workstations->last()], [
            'starts_on' => '2026-05-01', 'ends_on' => '2027-05-01', 'amount' => '84,90',
        ]))->sole();

        $this->assertCount(4, $firstFour);
        $this->assertSame(4, $firstFour->pluck('id')->unique()->count());
        foreach ($firstFour as $coverage) {
            $this->assertSame('2026-04-20', $coverage->starts_on->toDateString());
            $this->assertSame('2027-04-20', $coverage->ends_on->toDateString());
            $this->assertSame(7990, $coverage->price_cents);
        }
        $this->assertSame('2026-05-01', $fifth->starts_on->toDateString());
        $this->assertSame('2027-05-01', $fifth->ends_on->toDateString());
        $this->assertSame(8490, $fifth->price_cents);
        $this->assertSame(5, Coverage::query()->distinct()->count('workstation_id'));
        $this->assertDatabaseCount('coverages', 5);
        $this->assertDatabaseCount('coverage_assignments', 5);
        $this->assertSame('2026-12-31', $company->refresh()->technical_expires_on->toDateString());
        Http::assertNothingSent();
    }

    #[DataProvider('validAmounts')]
    public function test_prices_are_stored_as_exact_integer_cents(string $amount, int $cents): void
    {
        $coverage = app(CoverageService::class)->create($this->input([$this->workstation($this->company())], ['amount' => $amount]))->sole();

        $this->assertSame($cents, $coverage->price_cents);
        $this->assertDatabaseHas('coverages', ['id' => $coverage->id, 'price_cents' => $cents]);
    }

    public static function validAmounts(): array
    {
        return [
            ['1234,56', 123456], ['1234.56', 123456], ['0.10', 10], ['0.00', 0],
            ['42', 4200], ['1.2', 120], ['999999999.99', 99999999999],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_prices_cannot_create_a_coverage(mixed $amount): void
    {
        $this->assertRejected(fn () => app(CoverageService::class)->create($this->input([
            $this->workstation($this->company()),
        ], ['amount' => $amount])), 'amount');

        $this->assertDatabaseCount('coverages', 0);
        $this->assertDatabaseCount('coverage_assignments', 0);
    }

    public static function invalidAmounts(): array
    {
        return [
            'negative' => ['-0.01'], 'exponent' => ['1e2'], 'comma thousands' => ['1,000'],
            'dot thousands' => ['1.000'], 'Italian thousands' => ['1.000,00'], 'space thousands' => ['1 000'],
            'extra decimals' => ['12.345'], 'too large' => ['1000000000.00'],
            'integer input' => [10], 'float input' => [12.34], 'missing' => [null],
        ];
    }

    public function test_period_boundaries_are_inclusive_and_a_valid_leap_day_can_be_the_only_covered_day(): void
    {
        $workstation = $this->workstation($this->company());
        $service = app(CoverageService::class);
        $service->create($this->input([$workstation]));
        $this->assertRejected(fn () => $service->create($this->input([$workstation], [
            'starts_on' => '2027-04-20', 'ends_on' => '2028-02-28',
        ])), 'starts_on');
        $service->create($this->input([$workstation], ['starts_on' => '2027-04-21', 'ends_on' => '2028-02-28']));
        $leapDay = $service->create($this->input([$workstation], [
            'starts_on' => '2028-02-29', 'ends_on' => '2028-02-29', 'amount' => '0.10',
        ]))->sole();

        $this->assertSame('2028-02-29', $leapDay->starts_on->toDateString());
        $this->assertSame('2028-02-29', $leapDay->ends_on->toDateString());
        $this->assertDatabaseCount('coverages', 3);
        $this->assertDatabaseCount('coverage_assignments', 3);
    }

    #[DataProvider('invalidPeriods')]
    public function test_invalid_dates_or_reversed_periods_are_rejected(string $start, string $end, string $field): void
    {
        $this->assertRejected(fn () => app(CoverageService::class)->create($this->input([
            $this->workstation($this->company()),
        ], ['starts_on' => $start, 'ends_on' => $end])), $field);

        $this->assertDatabaseCount('coverages', 0);
        $this->assertDatabaseCount('coverage_assignments', 0);
    }

    public static function invalidPeriods(): array
    {
        return [
            'invalid leap day' => ['2027-02-29', '2027-03-01', 'starts_on'],
            'impossible end day' => ['2028-04-01', '2028-04-31', 'ends_on'],
            'non-padded date' => ['2026-4-20', '2027-04-20', 'starts_on'],
            'Italian date input' => ['20/04/2026', '2027-04-20', 'starts_on'],
            'timestamp instead of date' => ['2026-04-20T00:00:00', '2027-04-20', 'starts_on'],
            'end before start' => ['2026-04-20', '2026-04-19', 'ends_on'],
        ];
    }

    #[DataProvider('invalidSelections')]
    public function test_a_failed_multi_workstation_creation_is_atomic(string $failure): void
    {
        $company = $this->company();
        $first = $this->workstation($company, 'Prima');
        $second = $this->workstation($company, 'Seconda');
        $input = $this->input([$first, $second]);
        $existing = null;
        if ($failure === 'overlap') {
            $existing = app(CoverageService::class)->create($this->input([$second]))->sole();
        } elseif ($failure === 'mixed company') {
            $input['workstation_ids'][] = $this->workstation($this->company())->id;
        } else {
            $input['workstation_ids'][] = $first->id;
        }

        $this->assertRejected(fn () => app(CoverageService::class)->create($input), $failure === 'overlap' ? 'starts_on' : null);

        $this->assertDatabaseCount('coverages', $existing ? 1 : 0);
        $this->assertDatabaseCount('coverage_assignments', $existing ? 1 : 0);
        $this->assertDatabaseMissing('coverages', ['workstation_id' => $first->id]);
        if ($existing) {
            $this->assertSame($existing->id, Coverage::sole()->id);
        }
    }

    public static function invalidSelections(): array
    {
        return [['overlap'], ['mixed company'], ['duplicate ID']];
    }

    public function test_editing_preserves_previous_periods_and_cannot_change_ownership_or_partly_save_an_overlap(): void
    {
        $company = $this->company();
        $workstation = $this->workstation($company);
        $other = $this->workstation($company, 'Altra');
        $service = app(CoverageService::class);
        $previous = $service->create($this->input([$workstation], ['starts_on' => '2025-04-20', 'ends_on' => '2026-04-19']))->sole();
        $coverage = $service->create($this->input([$workstation]))->sole();
        $before = $coverage->fresh()->toArray();
        $previousBefore = $previous->fresh()->toArray();
        $assignmentsBefore = CoverageAssignment::orderBy('id')->get()->toArray();

        $this->assertRejected(fn () => $service->update($coverage, [
            'starts_on' => '2026-04-19', 'ends_on' => '2027-04-20', 'amount' => '999',
            'invoice_reference' => 'Non salvare',
        ]), 'starts_on');
        $this->assertSame($before, $coverage->refresh()->toArray());
        $updated = $service->update($coverage, [
            'starts_on' => '2026-04-20', 'ends_on' => '2027-05-01', 'amount' => '0.10',
            'invoice_reference' => 'Fattura aggiornata', 'quote_reference' => 'Preventivo aggiornato',
            'company_id' => $this->company()->id, 'workstation_id' => $other->id,
            'id' => $previous->id, 'price_cents' => 999999, 'released_at' => now(),
        ]);

        $this->assertSame($coverage->id, $updated->id);
        $this->assertSame($company->id, $updated->company_id);
        $this->assertSame($workstation->id, $updated->workstation_id);
        $this->assertSame(10, $updated->price_cents);
        $this->assertSame('2027-05-01', $updated->ends_on->toDateString());
        $this->assertSame('Fattura aggiornata', $updated->invoice_reference);
        $this->assertSame('Preventivo aggiornato', $updated->quote_reference);
        $this->assertSame($previousBefore, $previous->refresh()->toArray());
        $this->assertSame($assignmentsBefore, CoverageAssignment::orderBy('id')->get()->toArray());
    }

    public function test_transfer_preserves_purchased_facts_and_history_and_repeating_the_same_target_is_idempotent(): void
    {
        $company = $this->company();
        $source = $this->workstation($company, 'PC rotto');
        $target = $this->workstation($company, 'PC sostitutivo');
        $third = $this->workstation($company, 'Altro sostitutivo');
        $service = app(CoverageService::class);
        $previous = $service->create($this->input([$source], ['starts_on' => '2025-04-20', 'ends_on' => '2026-04-19']))->sole();
        $previousBefore = $previous->fresh()->toArray();
        $coverage = $service->create($this->input([$source]))->sole();
        $facts = $coverage->fresh()->toArray();
        unset($facts['workstation_id'], $facts['updated_at']);
        $firstAssignment = $coverage->assignments()->sole();
        $this->travel(2)->days();

        $transferred = $service->transfer($coverage, $target);

        $this->assertSame($facts, array_intersect_key($transferred->toArray(), $facts));
        $this->assertSame($target->id, $transferred->workstation_id);
        $this->assertSame('PC rotto', $source->refresh()->name);
        $this->assertSame($previousBefore, $previous->refresh()->toArray());
        $this->assertCount(2, $transferred->assignments);
        $this->assertTrue($firstAssignment->refresh()->released_at->equalTo(now()));
        $active = $transferred->assignments()->whereNull('released_at')->sole();
        $this->assertSame($target->id, $active->workstation_id);
        $this->assertTrue($active->assigned_at->equalTo(now()));
        $this->assertSame($transferred->id, $service->transfer($coverage, $target)->id);
        $service->transfer($transferred, $target);
        $this->assertSame(2, $transferred->assignments()->count());
        $this->assertSame(1, $transferred->assignments()->whereNull('released_at')->count());

        $this->assertRejected(fn () => $service->transfer($coverage, $third), 'target_workstation_id');
        $this->assertRejected(fn () => $service->update($coverage, [
            'starts_on' => '2026-04-20', 'ends_on' => '2027-04-20', 'amount' => '1',
        ]), 'target_workstation_id');
        $this->assertSame($target->id, $transferred->refresh()->workstation_id);
        $this->assertSame(7990, $transferred->price_cents);
        $this->assertSame(2, $transferred->assignments()->count());
    }

    #[DataProvider('invalidTransfers')]
    public function test_an_invalid_transfer_preserves_the_original_assignment_and_price(string $failure): void
    {
        $company = $this->company();
        $source = $this->workstation($company);
        $target = $this->workstation($failure === 'other company' ? $this->company() : $company, 'Destinazione');
        $service = app(CoverageService::class);
        $coverage = $service->create($this->input([$source]))->sole();
        if ($failure === 'overlap') {
            $service->create($this->input([$target], ['starts_on' => '2027-04-20', 'ends_on' => '2028-04-20']));
        }
        $before = $coverage->fresh()->toArray();
        $assignments = CoverageAssignment::orderBy('id')->get()->toArray();

        $this->assertRejected(fn () => $service->transfer($coverage, $target), $failure === 'overlap' ? 'starts_on' : 'target_workstation_id');

        $this->assertSame($before, $coverage->refresh()->toArray());
        $this->assertSame($assignments, CoverageAssignment::orderBy('id')->get()->toArray());
    }

    public static function invalidTransfers(): array
    {
        return [['other company'], ['overlap']];
    }

    public function test_later_company_and_endpoint_sync_cannot_change_commercial_periods_prices_or_assignment_history(): void
    {
        $company = $this->company();
        $workstation = $this->workstation($company, 'Nome commerciale locale');
        $service = app(CoverageService::class);
        $service->create($this->input([$workstation], ['starts_on' => '2025-04-20', 'ends_on' => '2026-04-19']));
        $service->create($this->input([$workstation]));
        $coverages = Coverage::orderBy('id')->get()->toArray();
        $assignments = CoverageAssignment::orderBy('id')->get()->toArray();
        GravityZoneSetting::create(['id' => 1, 'api_url' => 'https://gravityzone.example.test/api', 'api_key' => 'fake-test-key']);
        $item = ['id' => 'endpoint-1', 'name' => 'PC-API', 'platform' => 'Windows', 'is_managed' => true, 'is_isolated' => false];
        $this->mock(GravityZoneClient::class, function (MockInterface $mock) use ($company, $item): void {
            $mock->shouldReceive('getCompaniesList')->once()->andReturn([['id' => $company->gravityzone_id, 'name' => 'Nuovo nome aziendale API']]);
            $mock->shouldReceive('getLicenseInfo')->once()->andReturn(['technical_license_type' => 'Licenza', 'technical_expires_on' => '2030-01-01']);
            $mock->shouldReceive('getNetworkInventoryItems')->times(4)->andReturn([$item], [array_replace($item, ['name' => 'PC-RINOMINATO'])], [], [$item]);
        });
        $sync = app(EndpointSynchronizer::class);
        $sync->sync();
        $endpoint = Endpoint::sole();
        $workstation->linkEndpoint($endpoint);
        app(CompanySynchronizer::class)->sync();
        $sync->sync();
        $this->assertSame('PC-RINOMINATO', $endpoint->refresh()->name);
        $sync->sync();
        $this->assertFalse($endpoint->refresh()->is_present);
        $sync->sync();

        $this->assertTrue($endpoint->refresh()->is_present);
        $this->assertSame($endpoint->id, $workstation->refresh()->endpoint_id);
        $this->assertSame('Nome commerciale locale', $workstation->name);
        $this->assertSame('2030-01-01', $company->refresh()->technical_expires_on->toDateString());
        $this->assertSame($coverages, Coverage::orderBy('id')->get()->toArray());
        $this->assertSame($assignments, CoverageAssignment::orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill([
            'gravityzone_id' => 'company-'.(Company::count() + 1), 'name' => 'Azienda esempio',
            'technical_expires_on' => '2026-12-31', 'last_seen_at' => now(),
        ])->save();

        return $company;
    }

    private function workstation(Company $company, string $name = 'PC locale'): Workstation
    {
        return Workstation::create(['company_id' => $company->id, 'name' => $name]);
    }

    private function input(array $workstations, array $changes = []): array
    {
        return array_replace([
            'company_id' => $workstations[0]->company_id,
            'workstation_ids' => array_map(fn (Workstation $workstation): int => $workstation->id, $workstations),
            'starts_on' => '2026-04-20', 'ends_on' => '2027-04-20', 'amount' => '79,90',
            'invoice_reference' => 'FAT-2026-001', 'quote_reference' => 'PREV-2026-001',
        ], $changes);
    }

    private function assertRejected(callable $action, ?string $field = null): void
    {
        try {
            $action();
            $this->fail('L’operazione commerciale non valida deve essere rifiutata.');
        } catch (ValidationException $exception) {
            if ($field !== null) {
                $this->assertArrayHasKey($field, $exception->errors());
            } else {
                $this->assertNotEmpty($exception->errors());
            }
        }
    }
}
