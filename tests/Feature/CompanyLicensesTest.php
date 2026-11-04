<?php

namespace Tests\Feature;

use App\Filament\Pages\Licenze;
use App\Livewire\CompanyLicenses;
use App\Models\Company;
use App\Models\Computer;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
            ->assertCanRenderTableColumn('company_product')
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

    public function test_license_selector_shows_all_matching_companies_and_limits_the_company_options_without_api_calls(): void
    {
        $first = $this->company('Prima');
        $second = $this->company('Seconda');
        $other = $this->company('Altra');
        $unknown = $this->company('Sconosciuta');
        foreach ([$first, $second] as $company) {
            $company->forceFill(['technical_product' => 'Endpoint Security · MSP Secure'])->save();
        }
        $other->forceFill(['technical_product' => 'Bitdefender EDR'])->save();
        $pcs = [];
        foreach ([$first, $second, $other, $unknown] as $company) {
            $pcs[] = Computer::create(['company_id' => $company->id, 'name' => 'PC filtro '.$company->id]);
        }
        $before = Computer::orderBy('id')->get()->toArray();
        $page = Livewire::test(Licenze::class)->set('data.company_id', $other->id)
            ->set('data.license', 'Endpoint Security · MSP Secure')->assertSet('data.company_id', 'all')
            ->assertActionHidden('create')->assertSee($pcs[0]->name)->assertSee($pcs[1]->name)
            ->assertDontSee($pcs[2]->name)->assertDontSee($pcs[3]->name)
            ->assertFormFieldExists('company_id', function (Select $field) use ($first, $second): bool {
                $this->assertSame(['all', $first->id, $second->id], array_keys($field->getOptions()));
                $this->assertSame([], $field->getSearchResults('Altra'));

                return true;
            })
            ->assertFormFieldExists('license', fn (Select $field): bool => array_keys($field->getOptions()) === ['Bitdefender EDR', 'Endpoint Security · MSP Secure']);
        $page->set('data.company_id', $first->id)->assertCanSeeTableRecords([$pcs[0]])->assertCountTableRecords(1);
        $page->set('data.license', 'Bitdefender EDR')->assertSet('data.company_id', 'all')
            ->assertSee($pcs[2]->name)->assertDontSee($pcs[0]->name)->assertDontSee($pcs[1]->name)->assertDontSee($pcs[3]->name);
        $page->set('data.license', null)->assertSet('data.company_id', 'all')->assertSee($pcs[3]->name)
            ->assertFormFieldExists('company_id', fn (Select $field): bool => count($field->getOptions()) === 5);
        $page->set('data.license', 'Prodotto non presente')->assertSee('Nessuna azienda da mostrare.');
        $this->assertSame($before, Computer::orderBy('id')->get()->toArray());
        Http::assertNothingSent();
    }

    public function test_malformed_license_filter_is_rejected_without_query_errors(): void
    {
        Livewire::test(Licenze::class)->set('data.license', ['Bitdefender EDR'])->assertStatus(422);
        Http::assertNothingSent();
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
