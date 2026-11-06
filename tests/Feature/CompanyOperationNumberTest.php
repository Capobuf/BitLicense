<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRenewal;
use App\Models\Computer;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CompanyOperationNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_have_independent_stable_numbers_and_ordinal_labels_per_company(): void
    {
        $this->actingAs(User::factory()->create());
        $a = Company::forceCreate(['name' => 'Azienda A', 'gravityzone_id' => 'company-a', 'last_seen_at' => now()]);
        $b = Company::forceCreate(['name' => 'Azienda B', 'gravityzone_id' => 'company-b', 'last_seen_at' => now()]);
        $pc = Computer::create(['company_id' => $a->id, 'name' => 'PC A']);
        $data = ['kind' => 'initial', 'occurred_on' => '2026-01-01', 'quantity' => 1,
            'periods' => [['computer_id' => $pc->id, 'starts_on' => '2026-01-01', 'expires_on' => '2026-12-31']]];
        $first = $a->saveCommercialOperation($data, expectedComputerVersions: [$pc->id => 0]);
        $other = $this->operation($b);
        $renewal = $this->operation($a, 'renewal');
        $upgrade = $this->operation($a, 'upgrade');
        $second = $this->operation($a);
        $secondRenewal = $this->operation($a, 'renewal');
        $otherRenewal = $this->operation($b, 'renewal');
        $operations = collect([$first, $other, $second, $renewal, $secondRenewal, $otherRenewal, $upgrade]);
        $this->assertSame(['V1', 'V1', 'V2', 'R1', 'R2', 'R1', 'U1'], $operations->pluck('reference')->all());
        $this->assertSame(['Prima vendita', 'Prima vendita', 'Seconda vendita', 'Primo rinnovo', 'Secondo rinnovo', 'Primo rinnovo', 'Primo aggiornamento aziendale'],
            $operations->pluck('label')->all());
        $this->assertSame(['11ª vendita', '11° rinnovo'], [(new CompanyRenewal)->forceFill(['kind' => 'initial', 'number' => 11])->label, (new CompanyRenewal)->forceFill(['kind' => 'renewal', 'number' => 11])->label]);

        $corrected = $a->saveCommercialOperation(array_replace($data, ['amount' => '45']), $first->id, 0, [$pc->id => 1]);
        $this->assertSame('V1', $corrected->reference);
        $this->assertSame($first->id, $corrected->id);
        $second->forceFill(['is_regrouped' => true])->save();
        $this->assertSame('V3', $this->operation($a)->reference);
        $this->assertSame('V2', $second->fresh()->reference);
        $this->assertSame('V1', $other->fresh()->reference);

        $first->forceFill(['history' => [['event' => 'regrouped', 'recorded_at' => now()->toIso8601String(),
            'source_id' => $second->id, 'target_id' => $first->id, 'periods' => [['id' => 1]]]]])->save();
        $this->view('filament.commercial-operation', ['operation' => $first->fresh()])->assertSee('PC spostati da V2 a V1');

        DB::enableQueryLog();
        $operations = CompanyRenewal::all();
        DB::flushQueryLog();
        $operations->pluck('reference');
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_detected_sales_use_the_same_company_sequence_without_duplicates(): void
    {
        $a = Company::forceCreate(['name' => 'Azienda A', 'gravityzone_id' => 'company-a', 'last_seen_at' => now()]);
        $b = Company::forceCreate(['name' => 'Azienda B', 'gravityzone_id' => 'company-b', 'last_seen_at' => now()]);
        $this->operation($a);
        foreach ([$a, $b] as $company) {
            Computer::forceCreate(['company_id' => $company->id, 'name' => 'PC rilevato', 'first_seen_on' => '2026-02-07']);
            $this->assertSame(1, $company->initializeDetectedSales());
            $this->assertSame(0, $company->initializeDetectedSales());
        }
        $this->assertSame('V2', $a->renewals()->whereNotNull('detection_month')->sole()->reference);
        $this->assertSame('V1', $b->renewals()->sole()->reference);
    }

    public function test_existing_sales_are_numbered_without_changing_ids_or_data(): void
    {
        $a = Company::forceCreate(['name' => 'Azienda A', 'gravityzone_id' => 'company-a', 'last_seen_at' => now()]);
        $b = Company::forceCreate(['name' => 'Azienda B', 'gravityzone_id' => 'company-b', 'last_seen_at' => now()]);
        $this->operation($a);
        $this->operation($b);
        $this->operation($a, 'renewal');
        $this->operation($a);
        $this->operation($a, 'renewal');
        $migration = require database_path('migrations/2026_11_05_000001_number_operations_per_company.php');
        $migration->down();
        $before = DB::table('company_renewals')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration->up();
        $after = CompanyRenewal::orderBy('id')->get();
        $this->assertSame(['V1', 'V1', 'R1', 'V2', 'R2'], $after->pluck('reference')->all());
        $this->assertSame($before, DB::table('company_renewals')->orderBy('id')->get()
            ->map(fn ($row) => collect((array) $row)->except('number')->all())->all());
        $this->assertSame('V3', $this->operation($a)->reference);
        $this->assertSame('R3', $this->operation($a, 'renewal')->reference);
    }

    public function test_legacy_period_changing_kind_joins_the_new_sequence(): void
    {
        $this->actingAs(User::factory()->create());
        $company = Company::forceCreate(['name' => 'Azienda A', 'gravityzone_id' => 'company-a', 'last_seen_at' => now()]);
        $this->operation($company);
        $this->operation($company, 'renewal');
        $legacy = $this->operation($company);
        $this->assertSame('V2', $legacy->reference);
        $legacy = $company->saveLicensePeriod(['kind' => 'renewal', 'starts_on' => '2027-01-01', 'expires_on' => '2027-12-31', 'quantity' => 1], $legacy->id, 0);
        $this->assertSame(['R2', 'Secondo rinnovo'], [$legacy->reference, $legacy->label]);
        $this->assertSame('V2', $this->operation($company)->reference);
    }

    public function test_database_rejects_duplicate_numbers_for_the_same_company_and_kind(): void
    {
        $company = Company::forceCreate(['name' => 'Azienda A', 'gravityzone_id' => 'company-a', 'last_seen_at' => now()]);
        $first = $this->operation($company);
        $second = $this->operation($company);
        $this->assertSame(1, $this->operation($company, 'renewal')->number);
        $this->expectException(QueryException::class);
        DB::table('company_renewals')->where('id', $second->id)->update(['number' => $first->number]);
    }

    private function operation(Company $company, string $kind = 'initial'): CompanyRenewal
    {
        return $company->renewals()->forceCreate(['kind' => $kind, 'starts_on' => '2026-01-01', 'expires_on' => '2026-12-31', 'quantity' => 1]);
    }
}
