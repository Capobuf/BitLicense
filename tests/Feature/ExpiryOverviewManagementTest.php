<?php

namespace Tests\Feature;

use App\Filament\Pages\Panoramica;
use App\Models\Company;
use App\Models\Coverage;
use App\Models\Endpoint;
use App\Models\User;
use App\Models\Workstation;
use App\Services\CoverageService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ExpiryOverviewManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(12, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        $this->actingAs(User::factory()->create());
    }

    public function test_default_overview_separates_technical_and_commercial_expiries_and_keeps_current_missing_or_gap_alerts(): void
    {
        $company = $this->company();
        $expired = $this->workstation($company, 'PC scaduto');
        $this->coverage($expired, today()->subYear()->toDateString(), today()->subDay()->toDateString());
        $missing = $this->workstation($company, 'PC senza copertura');
        $gap = $this->workstation($company, 'PC con periodo futuro');
        $this->coverage($gap, today()->addDays(120)->toDateString(), today()->addDays(150)->toDateString());
        $distant = $this->workstation($company, 'PC oltre trenta giorni');
        $this->coverage($distant, today()->toDateString(), today()->addDays(60)->toDateString());
        $endpoint = new Endpoint;
        $endpoint->forceFill(['company_id' => $company->id, 'gravityzone_id' => 'new-endpoint', 'name' => 'Endpoint da associare', 'is_present' => true, 'last_seen_at' => now()])->save();

        $this->get('/admin')->assertOk()->assertSee('Scadenzario e avvisi interni');
        Livewire::test(Panoramica::class)
            ->assertCanSeeTableRecords(['workstation-'.$expired->id, 'workstation-'.$gap->id, 'company-'.$company->id, 'endpoint-'.$endpoint->id, 'workstation-'.$missing->id], inOrder: true)
            ->assertCanNotSeeTableRecords(['workstation-'.$distant->id])
            ->assertSee('Tecnica aziendale')->assertSee('Commerciale postazione')
            ->assertSee('Rinnovo da gestire')->assertSee('Prossimo inizio commerciale')
            ->assertSee('Data di riferimento')->assertSee('Non disponibile')
            ->assertSee(today()->addDays(5)->format('d/m/Y'))->assertSee(today()->subDay()->format('d/m/Y'))
            ->assertCountTableRecords(5);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_company_period_type_status_and_search_filters_change_the_actual_custom_records(): void
    {
        $first = $this->company();
        $second = $this->company();
        $near = $this->workstation($first, 'PC vicino');
        $distant = $this->workstation($second, 'PC sessanta giorni');
        $this->coverage($near, today()->toDateString(), today()->addDays(10)->toDateString());
        $this->coverage($distant, today()->toDateString(), today()->addDays(60)->toDateString());

        Livewire::test(Panoramica::class)->assertCanNotSeeTableRecords(['workstation-'.$distant->id])
            ->filterTable('period', '90')->assertCanSeeTableRecords(['workstation-'.$distant->id])
            ->filterTable('type', 'commercial')->assertCanSeeTableRecords(['workstation-'.$near->id, 'workstation-'.$distant->id])
            ->assertCanNotSeeTableRecords(['company-'.$first->id, 'company-'.$second->id])
            ->filterTable('status', 'active')->assertCanSeeTableRecords(['workstation-'.$distant->id])->assertCanNotSeeTableRecords(['workstation-'.$near->id])
            ->filterTable('company_id', $first->id)->assertCountTableRecords(0)
            ->filterTable('company_id', $second->id)->assertCountTableRecords(1)
            ->searchTable('inesistente')->assertCountTableRecords(0)
            ->searchTable('sessanta')->assertCanSeeTableRecords(['workstation-'.$distant->id]);
    }

    public function test_refresh_recomputes_rows_without_duplicate_alerts_business_writes_or_outbound_messages(): void
    {
        $company = $this->company();
        $local = $this->workstation($company, 'PC rinnovato');
        $coverage = $this->coverage($local, today()->subYear()->toDateString(), today()->subDay()->toDateString());
        $page = Livewire::test(Panoramica::class)->assertCanSeeTableRecords(['workstation-'.$local->id]);
        $before = $coverage->fresh()->getRawOriginal();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $page->callAction('refresh')->assertCountTableRecords(2);
        }
        $this->assertSame($before, $coverage->refresh()->getRawOriginal());
        $this->assertDatabaseCount('coverages', 1);
        $this->assertDatabaseCount('coverage_assignments', 1);

        app(CoverageService::class)->create([
            'company_id' => $company->id, 'workstation_ids' => [$local->id],
            'starts_on' => today()->toDateString(), 'ends_on' => today()->addYear()->toDateString(), 'amount' => '100',
        ]);
        $page->callAction('refresh')->assertCanNotSeeTableRecords(['workstation-'.$local->id])->assertDontSee('Rinnovo da gestire');
        $this->assertDatabaseCount('coverage_assignments', 2);
        Http::assertNothingSent();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_native_pagination_preserves_stable_row_keys_and_total_counts(): void
    {
        $company = $this->company();
        for ($number = 0; $number < 15; $number++) {
            $last = $this->workstation($company, 'PC pagina '.str_pad((string) $number, 2, '0', STR_PAD_LEFT));
        }
        Livewire::test(Panoramica::class)->assertCountTableRecords(16)
            ->assertCanNotSeeTableRecords(['workstation-'.$last->id])
            ->call('gotoPage', 2)->assertCanSeeTableRecords(['workstation-'.$last->id])
            ->assertCountTableRecords(16);
    }

    public function test_navigation_uses_the_related_list_with_company_and_name_filters(): void
    {
        $company = $this->company();
        $local = $this->workstation($company, 'PC da aprire');
        $this->workstation($this->company(), 'PC da escludere');
        $page = Livewire::test(Panoramica::class);
        $action = $page->instance()->getAction([['name' => 'open', 'context' => ['table' => true, 'recordKey' => 'workstation-'.$local->id]]]);
        $this->get($action->getUrl())->assertOk()->assertSee('PC da aprire')->assertDontSee('PC da escludere');
    }

    public function test_disabling_a_user_blocks_direct_access_and_a_refresh_on_an_open_dashboard(): void
    {
        $page = Livewire::test(Panoramica::class);
        $user = auth()->user();
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh());
        $page->call('mountAction', 'refresh')->assertForbidden();
        $this->get('/admin')->assertForbidden();
        Livewire::test(Panoramica::class)->assertForbidden();
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-'.Company::query()->count(), 'name' => 'Azienda test '.Company::query()->count(), 'last_seen_at' => now(), 'is_present' => true, 'technical_expires_on' => today()->addDays(5)->toDateString()])->save();

        return $company;
    }

    private function workstation(Company $company, string $name): Workstation
    {
        return Workstation::query()->create(['company_id' => $company->id, 'name' => $name]);
    }

    private function coverage(Workstation $workstation, string $start, string $end): Coverage
    {
        return app(CoverageService::class)->create(['company_id' => $workstation->company_id, 'workstation_ids' => [$workstation->id], 'starts_on' => $start, 'ends_on' => $end, 'amount' => '100'])->sole();
    }
}
