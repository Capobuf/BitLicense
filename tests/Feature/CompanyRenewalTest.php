<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyRenewal;
use App\Models\Computer;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompanyRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create(['role' => 'operator']));
    }

    public function test_legacy_aggregate_can_be_corrected_without_inventing_links_or_billing_history(): void
    {
        $company = $this->company();
        $computer = Computer::create(['company_id' => $company->id, 'name' => 'PC esempio'])->refresh();
        $computer->updateCommercial(['activated_on' => '2026-07-27', 'expires_on' => '2027-07-27', 'amount' => '49,99'], 0);
        $before = $computer->getAttributes();
        $period = $this->legacy($company);
        $updated = $company->saveLicensePeriod($this->data(quantity: 11), $period->id, 0);

        $this->assertSame(11, $updated->quantity);
        $this->assertSame(1, $updated->version);
        $this->assertNull($updated->billing_status);
        $this->assertNull($updated->occurred_on);
        $this->assertDatabaseCount('computer_periods', 0);
        $this->assertSame($before, $computer->refresh()->getAttributes());
        $company->saveLicensePeriod($this->data(quantity: 11), $period->id, 1);
        $this->assertSame(1, $period->refresh()->version);
        Http::assertNothingSent();
    }

    public function test_legacy_corrections_validate_dates_quantities_fields_and_versions(): void
    {
        $company = $this->company();
        $period = $this->legacy($company);
        foreach ([
            ['starts_on' => '2027-02-29'], ['expires_on' => '2026-07-26'], ['starts_on' => '27/07/2026'],
            ['quantity' => 0], ['quantity' => 1.5], ['quantity' => 4294967296], ['kind' => 'upgrade'],
            ['company_id' => $company->id], ['version' => 99],
        ] as $invalid) {
            $this->assertRejected(fn () => $company->saveLicensePeriod(array_replace($this->data(), $invalid), $period->id, 0));
        }
        $this->assertRejected(fn () => $company->saveLicensePeriod($this->data(), $period->id));
        $this->assertRejected(fn () => $company->saveLicensePeriod($this->data(), $period->id, 1));
        $this->assertRejected(fn () => $company->saveLicensePeriod($this->data()));
        $this->assertSame(0, $period->refresh()->version);
        $this->assertDatabaseCount('company_renewals', 1);
    }

    public function test_legacy_correction_cannot_reach_another_company(): void
    {
        $first = $this->company();
        $second = $this->company();
        $period = $this->legacy($first);
        $this->expectException(ModelNotFoundException::class);
        $second->saveLicensePeriod($this->data(), $period->id, 0);
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-'.Company::count(), 'name' => 'Azienda esempio', 'last_seen_at' => now()])->save();

        return $company;
    }

    private function legacy(Company $company): CompanyRenewal
    {
        $period = $company->renewals()->make();
        $period->forceFill($this->data())->save();

        return $period->refresh();
    }

    private function data(int $quantity = 8): array
    {
        return ['kind' => 'initial', 'starts_on' => '2026-07-27', 'expires_on' => '2027-07-27', 'quantity' => $quantity];
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
