<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Licenze;
use App\Livewire\CompanyRenewals;
use App\Models\Company;
use App\Models\Computer;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class DetectedSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 4)->startOfDay());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
    }

    public function test_first_sales_group_by_company_month_and_year_without_inventing_expiry_or_invoice(): void
    {
        $company = $this->company();
        $a = $this->pc($company, '2026-02-07');
        $b = $this->pc($company, '2026-02-22');
        $c = $this->pc($company, '2026-03-03');
        $d = $this->pc($company, '2025-02-07');
        $other = $this->company();
        $e = $this->pc($other, '2026-02-07');
        $this->artisan('bitlicense:detect-sales')->assertSuccessful();
        $this->assertDatabaseCount('company_renewals', 4);
        $this->assertSame($a->fresh()->effectivePeriod()->company_renewal_id, $b->fresh()->effectivePeriod()->company_renewal_id);
        $this->assertNotSame($a->fresh()->effectivePeriod()->company_renewal_id, $c->fresh()->effectivePeriod()->company_renewal_id);
        $sale = $a->fresh()->effectivePeriod()->operation;
        $this->assertSame('2026-02', $sale->detection_month);
        $this->assertSame('2026-02-07', $sale->occurred_on->toDateString());
        $this->assertSame(2, $sale->quantity);
        $this->assertNull($sale->billing_status);
        $this->assertNull($sale->expires_on);
        $this->assertNull($sale->price_cents);
        $this->assertNull($a->fresh()->expires_on);
        $this->assertSame(0, $a->fresh()->renewalCount());
        $version = $a->fresh()->commercial_version;
        $this->assertSame(0, $company->initializeDetectedSales());
        $this->assertSame($version, $a->fresh()->commercial_version);
        $this->view('filament.computer-cycles', ['computer' => $a->fresh()])->assertSee('Prima vendita')->assertSee('Da completare');
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->filterTable('status', 'missing')->assertCanSeeTableRecords([$a, $b, $c, $d]);
        Livewire::test(Dashboard::class)->assertOk();
        Http::assertNothingSent();
    }

    public function test_seven_plus_three_can_be_regrouped_without_changing_dates_and_repeated_detection_preserves_it(): void
    {
        $company = $this->company();
        $first = collect(range(1, 7))->map(fn ($day) => $this->pc($company, sprintf('2026-02-%02d', $day)));
        $later = collect(range(1, 3))->map(fn ($day) => $this->pc($company, sprintf('2026-03-%02d', $day)));
        $company->initializeDetectedSales();
        $target = $company->renewals()->where('detection_month', '2026-02')->sole();
        $source = $company->renewals()->where('detection_month', '2026-03')->sole();
        Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])
            ->callAction(TestAction::make('moveComputers')->table($source), data: ['computer_ids' => $later->pluck('id')->all(), 'target_id' => $target->id])
            ->assertHasNoActionErrors();
        $this->assertCount(10, $target->fresh()->periods);
        $this->assertSame(10, $target->fresh()->quantity);
        $this->assertTrue($source->fresh()->is_regrouped);
        $this->assertSame(0, $source->fresh()->quantity);
        $this->assertSame('2026-03-01', $later[0]->fresh()->activated_on->toDateString());
        $this->assertSame(0, $company->initializeDetectedSales());
        $this->assertCount(10, $target->fresh()->periods);
        $this->view('filament.commercial-operation', ['operation' => $source->fresh()])->assertSee('3 PC spostati');
        $company->moveInitialPeriods($target->id, $source->id, [$later[0]->id], $this->versions($company), $this->pcVersions($company));
        $this->assertFalse($source->fresh()->is_regrouped);
        $this->assertSame(1, $source->fresh()->quantity);
        $this->assertCount(9, $target->fresh()->periods);
        Http::assertNothingSent();
    }

    public function test_partial_move_and_existing_paid_destination_preserve_amounts_and_allow_reference_correction(): void
    {
        $company = $this->company();
        $a = $this->pc($company, '2026-02-07');
        $b = $this->pc($company, '2026-03-01');
        $c = $this->pc($company, '2026-03-02');
        $company->initializeDetectedSales();
        $target = $a->fresh()->effectivePeriod()->operation;
        $source = $b->fresh()->effectivePeriod()->operation;
        Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])
            ->callAction(TestAction::make('edit')->table($target), data: ['invoice_reference' => 'TEST-1', 'amount' => '150,00'])
            ->assertHasNoActionErrors();
        $company->moveInitialPeriods($source->id, $target->id, [$b->id], $this->versions($company), $this->pcVersions($company));
        $this->assertSame(1, $source->fresh()->quantity);
        $this->assertFalse($source->fresh()->is_regrouped);
        $this->assertSame(15000, $target->fresh()->price_cents);
        $this->assertSame('TEST-1', $target->fresh()->invoice_reference);
        $company->moveInitialPeriods($source->id, $target->id, [$c->id], $this->versions($company), $this->pcVersions($company));
        Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])
            ->callAction(TestAction::make('edit')->table($source), data: ['quote_reference' => 'ARCHIVE-1'])
            ->assertHasNoActionErrors();
        $this->assertSame('ARCHIVE-1', $source->fresh()->quote_reference);
    }

    public function test_unknown_expiry_must_be_completed_before_renewal_or_upgrade_and_can_be_completed_in_register(): void
    {
        $company = $this->company();
        $a = $this->pc($company, '2026-02-07');
        $company->initializeDetectedSales();
        $sale = $a->fresh()->effectivePeriod()->operation;
        $upgrade = ['kind' => 'upgrade', 'occurred_on' => '2026-11-04', 'amount' => '10'];
        $this->reject(fn () => $company->saveCommercialOperation($upgrade, expectedComputerVersions: $this->pcVersions($company)));
        $renewal = ['kind' => 'renewal', 'occurred_on' => '2027-02-07', 'quantity' => 1,
            'periods' => [['computer_id' => $a->id, 'starts_on' => '2027-02-07', 'expires_on' => '2028-02-06']]];
        $this->reject(fn () => $company->saveCommercialOperation($renewal, expectedComputerVersions: $this->pcVersions($company)));
        Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])
            ->callAction(TestAction::make('edit')->table($sale), data: ['periods' => [['computer_id' => $a->id, 'starts_on' => '2026-02-07', 'expires_on' => '2027-02-06']]])
            ->assertHasNoActionErrors();
        $this->assertSame('2027-02-06', $a->fresh()->expires_on->toDateString());
        $company->saveCommercialOperation($renewal, expectedComputerVersions: $this->pcVersions($company));
        $this->assertSame(1, $a->fresh()->renewalCount());
        $this->assertSame('2027-02-06', $a->fresh()->expires_on->toDateString());
    }

    public function test_existing_dates_metadata_and_deliberately_cleared_dates_are_preserved(): void
    {
        $company = $this->company();
        $pc = $this->pc($company, '2026-02-07');
        $pc->forceFill(['activated_on' => '2026-02-08', 'activation_from_first_seen' => false, 'expires_on' => '2027-02-08', 'price_cents' => 5000, 'invoice_reference' => 'OLD-1'])->save();
        $cleared = $this->pc($company, '2026-03-01');
        $cleared->updateCommercial(['activated_on' => null], 0);
        $company->initializeDetectedSales();
        $this->assertSame('2026-02-08', $pc->fresh()->activated_on->toDateString());
        $this->assertSame('2027-02-08', $pc->fresh()->expires_on->toDateString());
        $this->assertSame('OLD-1', $pc->fresh()->invoice_reference);
        $this->assertNull($cleared->fresh()->activated_on);
        $this->assertCount(0, $cleared->fresh()->periods);
        $replacement = Computer::create(['company_id' => $company->id, 'name' => 'Sostituto']);
        $pc->refresh()->transferTo($replacement, $pc->commercial_version);
        $this->assertSame(5000, $replacement->price_cents);
        $this->assertSame('OLD-1', $replacement->invoice_reference);
        $this->assertSame('2027-02-08', $replacement->expires_on->toDateString());
    }

    public function test_empty_detected_import_remains_reconcilable_and_replaceable_without_duplicate_sales(): void
    {
        $company = $this->company();
        $remote = $this->pc($company, '2026-02-07');
        $company->forceFill(['technical_product' => 'Assigned license'])->save();
        $company->initializeDetectedSales();
        $this->assertSame('Assigned license', $remote->fresh()->effectivePeriod()->product);
        $this->assertFalse($remote->fresh()->hasCommercialData());
        $local = Computer::create(['company_id' => $company->id, 'name' => 'Manuale']);
        $local->reconcileWith($remote, 0);
        $this->assertDatabaseMissing('computers', ['id' => $remote->id]);
        $company->initializeDetectedSales();
        $this->assertCount(1, $local->fresh()->periods);
        $replacement = $this->pc($company, '2026-04-01');
        $company->initializeDetectedSales();
        $sale = $local->fresh()->effectivePeriod()->operation;
        Livewire::test(CompanyRenewals::class, ['companyId' => $company->id])
            ->callAction(TestAction::make('edit')->table($sale), data: ['periods' => [['computer_id' => $local->id, 'starts_on' => '2026-02-07', 'expires_on' => '2027-02-06']]])->assertHasNoActionErrors();
        $local->refresh()->transferTo($replacement, $local->commercial_version);
        $this->assertCount(1, $replacement->fresh()->periods);
        $this->assertSame(0, $company->initializeDetectedSales());
        $this->assertNull($local->fresh()->activated_on);
    }

    public function test_moves_reject_stale_versions_foreign_companies_and_disabled_users_atomically(): void
    {
        $company = $this->company();
        $a = $this->pc($company, '2026-02-07');
        $b = $this->pc($company, '2026-03-07');
        $company->initializeDetectedSales();
        $target = $a->fresh()->effectivePeriod()->operation;
        $source = $b->fresh()->effectivePeriod()->operation;
        $versions = $this->versions($company);
        $pcVersions = $this->pcVersions($company);
        $b->refresh()->updateCommercial(['notes' => 'Modifica concorrente'], $b->commercial_version);
        $this->reject(fn () => $company->moveInitialPeriods($source->id, $target->id, [$b->id], $versions, $pcVersions));
        $other = $this->company();
        $c = $this->pc($other, '2026-02-07');
        $other->initializeDetectedSales();
        $this->reject(fn () => $company->moveInitialPeriods($source->id, $c->fresh()->effectivePeriod()->company_renewal_id, [$b->id], $versions, $this->pcVersions($company)));
        $this->assertSame($source->id, $b->fresh()->effectivePeriod()->company_renewal_id);
        $user = auth()->user();
        $user->update(['is_active' => false]);
        $this->expectException(AuthorizationException::class);
        $company->moveInitialPeriods($source->id, $target->id, [$b->id], $versions, $this->pcVersions($company));
    }

    private function reject(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected validation failure');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    private function company(): Company
    {
        return Company::forceCreate(['name' => 'Azienda test', 'gravityzone_id' => 'company-'.Company::count(), 'last_seen_at' => now(), 'is_present' => true]);
    }

    private function pc(Company $company, string $date): Computer
    {
        return Computer::forceCreate(['company_id' => $company->id, 'gravityzone_company_id' => $company->id,
            'gravityzone_id' => 'pc-'.Computer::count(), 'technical_name' => 'PC '.Computer::count(), 'is_present' => true,
            'first_seen_on' => $date, 'activated_on' => $date, 'activation_from_first_seen' => true]);
    }

    private function versions(Company $company): array
    {
        return $company->renewals()->pluck('version', 'id')->all();
    }

    private function pcVersions(Company $company): array
    {
        return $company->computers()->pluck('commercial_version', 'id')->all();
    }
}
