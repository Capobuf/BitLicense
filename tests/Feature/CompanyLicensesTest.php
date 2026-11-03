<?php

namespace Tests\Feature;

use App\Filament\Pages\Licenze;
use App\Livewire\CompanyLicenses;
use App\Models\Company;
use App\Models\Computer;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyLicensesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Http::preventStrayRequests();
    }

    public function test_all_companies_includes_empty_companies_and_switches_back_to_one_table_without_api_calls(): void
    {
        $company = $this->company('Prima');
        $empty = $this->company('Vuota');
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC locale']);
        Livewire::test(Licenze::class)->set('data.company_id', 'all')
            ->assertSeeLivewire(CompanyLicenses::class)->assertActionHidden('create')
            ->set('data.company_id', $company->id)->assertCanSeeTableRecords([$pc])->assertActionEnabled('create');
        $page = Livewire::test(CompanyLicenses::class, ['companyId' => $empty->id])->assertCountTableRecords(0);
        $this->assertSame($empty->id, $page->instance()->getTable()->getHeader()->getData()['company']->id);
        Http::assertNothingSent();
    }

    public function test_embedded_tables_keep_records_filters_and_writes_within_their_own_company(): void
    {
        $company = $this->company('Prima');
        $other = $this->company('Seconda');
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC prima']);
        $foreign = Computer::create(['company_id' => $other->id, 'name' => 'PC seconda']);
        $first = Livewire::test(CompanyLicenses::class, ['companyId' => $company->id])
            ->assertCanSeeTableRecords([$pc])->assertCanNotSeeTableRecords([$foreign])
            ->searchTable('Nessuna corrispondenza')->assertCountTableRecords(0);
        $second = Livewire::test(CompanyLicenses::class, ['companyId' => $other->id])
            ->assertCanSeeTableRecords([$foreign])->assertCountTableRecords(1);
        $first->searchTable('')->callAction('create', data: ['name' => 'Nuovo PC prima'])->assertHasNoFormErrors();
        $this->assertDatabaseHas('computers', ['company_id' => $company->id, 'name' => 'Nuovo PC prima']);
        $first->callAction(TestAction::make('edit')->table($pc), data: ['quote_reference' => 'PR-1'])
            ->assertHasNoFormErrors()->assertTableColumnVisible('quote_reference');
        $second->assertTableColumnHidden('quote_reference');
        $first->call('mountAction', 'edit', [], ['table' => true, 'recordKey' => (string) $foreign->id])->assertActionNotMounted();
        $this->assertNull($foreign->refresh()->quote_reference);
        $first->set('tableRecordsPerPage', 25);
        Livewire::test(CompanyLicenses::class, ['companyId' => $other->id])->assertSet('tableRecordsPerPage', 10)
            ->set('tableRecordsPerPage', 50);
        Livewire::test(CompanyLicenses::class, ['companyId' => $company->id])->assertSet('tableRecordsPerPage', 25);
        Livewire::test(CompanyLicenses::class, ['companyId' => $other->id])->assertSet('tableRecordsPerPage', 50);
        Http::assertNothingSent();
    }

    public function test_embedded_company_scope_cannot_be_changed_by_the_browser(): void
    {
        $company = $this->company('Prima');
        $other = $this->company('Seconda');
        $page = Livewire::test(CompanyLicenses::class, ['companyId' => $company->id]);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $page->set('data.company_id', $other->id);
    }

    public function test_disabled_users_cannot_open_or_save_an_embedded_table(): void
    {
        $company = $this->company('Prima');
        $pc = Computer::create(['company_id' => $company->id, 'name' => 'PC prima']);
        $page = Livewire::test(CompanyLicenses::class, ['companyId' => $company->id])
            ->mountAction(TestAction::make('edit')->table($pc));
        $user = auth()->user();
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh());
        $page->callMountedAction()->assertForbidden();
        Livewire::test(CompanyLicenses::class, ['companyId' => $company->id])->assertForbidden();
        $this->assertNull($pc->refresh()->expires_on);
        Http::assertNothingSent();
    }

    private function company(string $name): Company
    {
        $company = new Company;
        $company->forceFill(['name' => $name, 'gravityzone_id' => 'company-'.Company::count(), 'last_seen_at' => now()])->save();

        return $company;
    }
}
