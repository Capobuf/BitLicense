<?php

namespace Tests\Feature;

use App\Filament\Pages\Licenze;
use App\Models\Company;
use App\Models\Computer;
use App\Models\GravityZoneSetting;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class LicenzeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $this->travelTo(new \DateTimeImmutable('2026-11-03 12:00 Europe/Rome'));
        Http::preventStrayRequests();
        Mail::fake();
    }

    public function test_one_operational_page_scopes_computers_and_keeps_the_company_expiry_in_the_same_table(): void
    {
        $company = $this->company();
        $pc = $this->computer($company, ['activated_on' => '2026-05-01', 'expires_on' => '2027-05-01']);
        $other = $this->computer($this->company());
        $page = Livewire::test(Licenze::class)->assertCountTableRecords(0)->assertActionDisabled('create');
        $page->set('data.company_id', $company->id)->assertCanSeeTableRecords([$pc])->assertCanNotSeeTableRecords([$other])
            ->assertSee('31/12/2026')->assertSee('01/05/2027')->assertSee('Scadenza tecnica')->assertSee('Validità')->assertSee('Prezzo');
        $this->get('/admin')->assertOk()->assertSee('Licenze')->assertDontSee('Coperture commerciali')->assertDontSee('Panoramica');
        foreach (['endpoint', 'postazioni', 'coperture'] as $path) {
            $this->get('/admin/'.$path)->assertNotFound();
        }
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_company_kpis_cover_all_local_computers_independently_of_search_and_filters(): void
    {
        $company = $this->company();
        foreach ([
            [],
            ['expires_on' => '2026-11-02'],
            ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-02'],
            ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-03'],
            ['activated_on' => '2026-01-01', 'expires_on' => '2026-12-03'],
            ['activated_on' => '2026-01-01', 'expires_on' => '2026-12-04'],
            ['activated_on' => '2026-12-01', 'expires_on' => '2027-12-01'],
            ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-01', 'is_present' => false],
        ] as $attributes) {
            $this->computer($company, $attributes);
        }
        $other = $this->company();
        $this->computer($other);
        $expected = ['Computer' => 8, 'Da completare' => 2, 'Scadute' => 2, 'In scadenza' => 2];
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id);
        $this->assertSame($expected, $page->instance()->getTable()->getHeader()->getData()['metrics']);
        $page->filterTable('status', 'expired')->assertCountTableRecords(2);
        $this->assertSame($expected, $page->instance()->getTable()->getHeader()->getData()['metrics']);
        $page->searchTable('Nessun computer con questo nome')->assertCountTableRecords(0);
        $this->assertSame($expected, $page->instance()->getTable()->getHeader()->getData()['metrics']);
        $page->set('data.company_id', $other->id);
        $this->assertSame(['Computer' => 1, 'Da completare' => 1, 'Scadute' => 0, 'In scadenza' => 0], $page->instance()->getTable()->getHeader()->getData()['metrics']);
        $page->set('data.company_id', $this->company()->id);
        $this->assertSame(['Computer' => 0, 'Da completare' => 0, 'Scadute' => 0, 'In scadenza' => 0], $page->instance()->getTable()->getHeader()->getData()['metrics']);
        Http::assertNothingSent();
    }

    public function test_company_picker_shows_escaped_names_and_technical_status_without_api_calls(): void
    {
        $company = $this->company();
        $company->forceFill(['name' => 'D’Amìco & Figli <img src=x onerror=alert(1)>', 'is_suspended' => false])->save();
        $suspended = $this->company();
        $suspended->forceFill(['is_suspended' => true, 'technical_expires_on' => null])->save();
        $unknown = $this->company();
        $this->computer($company, ['expires_on' => '2026-11-01']);

        Livewire::test(Licenze::class)->assertSee(['Tutte le aziende', $company->name])
            ->assertSeeHtml('data-name="'.e($company->name).'"')->assertDontSeeHtml('<img src=x')
            ->assertSeeHtmlInOrder([$suspended->name, 'licenza aziendale Sospesa', $unknown->name, 'licenza aziendale Stato non disponibile', e($company->name), 'licenza aziendale Attiva']);

        $state = fn (array $attributes): array => (new Company)->forceFill($attributes + ['is_present' => true, 'is_suspended' => false])->technicalStatus();
        $this->assertSame(['Scaduta', 'danger'], $state(['technical_expires_on' => '2026-11-02']));
        $this->assertSame(['In scadenza', 'warning'], $state(['technical_expires_on' => '2026-11-03']));
        $this->assertSame(['In scadenza', 'warning'], $state(['technical_expires_on' => '2026-12-03']));
        $this->assertSame(['Attiva', 'success'], $state(['technical_expires_on' => '2026-12-04']));
        $this->assertSame(['Scadenza non disponibile', 'gray'], $state(['technical_expires_on' => null]));
        $this->assertSame(['Non più rilevata', 'gray'], $state(['is_present' => false, 'technical_expires_on' => '2026-12-04']));
        Http::assertNothingSent();
    }

    public function test_validity_stacks_dates_and_sorts_by_commercial_expiry(): void
    {
        $company = $this->company();
        $late = $this->computer($company, ['activated_on' => '2026-05-01', 'expires_on' => '2028-05-01']);
        $early = $this->computer($company, ['activated_on' => '2026-04-20', 'expires_on' => '2027-04-20']);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->assertSee('Validità')->assertSee('Prezzo')->assertDontSee('Scadenza commerciale')
            ->sortTable('validity')->assertCanSeeTableRecords([$early, $late], inOrder: true);
        $html = $page->instance()->getTable()->getColumn('validity')->record($early)->toHtml();
        $this->assertLessThan(strpos($html, '20/04/2027'), strpos($html, '20/04/2026'));
        $page->mountAction(TestAction::make('edit')->table($early))->assertActionMounted(TestAction::make('edit')->table($early));
        $this->assertStringNotContainsString('bl-computer-editor', $page->html());
        Http::assertNothingSent();
    }

    public function test_reference_columns_follow_all_company_rows_and_update_after_modal_saves(): void
    {
        $company = $this->company();
        $empty = $this->computer($company, ['quote_reference' => '   ', 'invoice_reference' => '']);
        $this->computer($this->company(), ['quote_reference' => 'PR-ESTERNO', 'invoice_reference' => 'FT-ESTERNA']);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->assertTableColumnHidden('quote_reference')->assertTableColumnHidden('invoice_reference');
        $page->callAction(TestAction::make('edit')->table($empty), data: ['quote_reference' => 'PR-1'])
            ->assertHasNoFormErrors()->assertTableColumnVisible('quote_reference')->assertTableColumnHidden('invoice_reference');
        $page->callAction(TestAction::make('edit')->table($empty), data: ['invoice_reference' => 'FT-1'])
            ->assertHasNoFormErrors()->assertTableColumnVisible('quote_reference')->assertTableColumnVisible('invoice_reference');
        $page->searchTable('Nessuna corrispondenza')->assertCountTableRecords(0)
            ->assertTableColumnVisible('quote_reference')->assertTableColumnVisible('invoice_reference');
        $page->searchTable('')->callAction(TestAction::make('edit')->table($empty), data: ['quote_reference' => '', 'invoice_reference' => ''])
            ->assertHasNoFormErrors()->assertTableColumnHidden('quote_reference')->assertTableColumnHidden('invoice_reference');
        $page->set('data.company_id', $this->company()->id)
            ->assertTableColumnHidden('quote_reference')->assertTableColumnHidden('invoice_reference');
        Http::assertNothingSent();
    }

    public function test_click_edit_completes_an_imported_pc_without_optional_fields_or_changing_remote_values(): void
    {
        $company = $this->company();
        $pc = $this->computer($company, ['gravityzone_id' => 'remote-1', 'gravityzone_company_id' => $company->id, 'technical_name' => 'PC-GZ', 'name' => null]);
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->assertSee('Da completare')
            ->mountAction(TestAction::make('edit')->table($pc))->fillForm(['activated_on' => '2026-05-01', 'expires_on' => '2027-05-01'])
            ->set('mountedActions.0.data.technical_name', 'MANOMESSO')->set('mountedActions.0.data.company_id', 9999)
            ->callMountedAction()->assertHasNoFormErrors()->assertNotified('Dati salvati');
        $this->assertSame('2027-05-01', $pc->refresh()->expires_on->toDateString());
        $this->assertSame('PC-GZ', $pc->technical_name);
        $this->assertSame($company->id, $pc->company_id);
        $this->assertNull($pc->price_cents);
        $this->assertSame('2026-12-31', $company->refresh()->technical_expires_on->toDateString());
        $this->assertDatabaseCount('computers', 1);
    }

    public function test_manual_pc_is_created_and_edited_in_the_selected_company_with_optional_exact_price(): void
    {
        $company = $this->company();
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id);
        $page->callAction('create', data: ['name' => 'PC manuale'])->assertHasNoFormErrors()->assertNotified('Dati salvati');
        $pc = Computer::sole();
        $this->assertNull($pc->gravityzone_id);
        $page->callAction(TestAction::make('edit')->table($pc), data: [
            'name' => 'PC ufficio', 'activated_on' => '2026-04-20', 'expires_on' => '2027-04-20',
            'amount' => '79,90', 'invoice_reference' => 'FT-12', 'quote_reference' => 'PR-14', 'notes' => 'Note locali',
        ])->assertHasNoFormErrors();
        $this->assertSame($company->id, $pc->refresh()->company_id);
        $this->assertSame(7990, $pc->price_cents);
        $this->assertSame('Note locali', $pc->notes);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_annual_suggestion_handles_leap_day_and_keeps_an_operator_chosen_expiry(): void
    {
        $company = $this->company();
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->mountAction('create')
            ->set('mountedActions.0.data.activated_on', '2028-02-29')->assertActionDataSet(['expires_on' => '2029-02-28'])
            ->set('mountedActions.0.data.expires_on', '2030-12-31')->set('mountedActions.0.data.activated_on', '2028-03-01')
            ->assertActionDataSet(['expires_on' => '2030-12-31']);
    }

    public function test_company_product_is_read_only_and_follows_remote_company_without_overwriting_saved_local_product(): void
    {
        $company = $this->company();
        $company->forceFill(['technical_product' => 'Endpoint Security · MSP Secure'])->save();
        $other = $this->company();
        $other->forceFill(['technical_product' => 'Bitdefender EDR'])->save();
        $manual = $this->computer($company, ['product' => 'Prodotto precedente']);
        $moved = $this->computer($company, ['gravityzone_id' => 'remote-moved', 'gravityzone_company_id' => $other->id]);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->assertCanRenderTableColumn('company_product')
            ->assertTableColumnStateSet('company_product', 'Endpoint Security · MSP Secure', $manual)
            ->assertTableColumnStateSet('company_product', 'Bitdefender EDR', $moved)
            ->assertSee('Endpoint Security · MSP Secure')->assertSee('Bitdefender EDR');
        $columns = $page->get('tableColumns');
        foreach ($columns as &$column) {
            if ($column['name'] === 'company_product') {
                $column['isToggleable'] = true;
                $column['isToggled'] = false;
                $column['isToggledHiddenByDefault'] = true;
            }
        }
        session()->put($page->instance()->getTableColumnsSessionKey(), $columns);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->assertCanRenderTableColumn('company_product')
            ->mountAction(TestAction::make('edit')->table($manual))->assertFormFieldDoesNotExist('product');
        $html = $page->instance()->mountedActionSchema0->toHtml();
        $this->assertStringContainsString('Endpoint Security · MSP Secure', $html);
        $this->assertStringContainsString('Prodotto precedente', $html);
        $page->set('mountedActions.0.data.company_product', 'MANOMESSO')
            ->set('mountedActions.0.data.product', 'MANOMESSO')
            ->fillForm(['notes' => 'Nota aggiornata'])->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame('Prodotto precedente', $manual->refresh()->product);
        $this->assertSame('Endpoint Security · MSP Secure', $company->refresh()->technical_product);

        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)->mountAction('create');
        $this->assertStringContainsString('Endpoint Security · MSP Secure', $page->instance()->mountedActionSchema0->toHtml());
        $other->forceFill(['technical_product' => null])->save();
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('edit')->table($moved));
        $this->assertStringContainsString('Non fornito dall’API', $page->instance()->mountedActionSchema0->toHtml());
        Http::assertNothingSent();
    }

    public function test_edit_header_uses_the_detected_company_and_keeps_technical_details_compact(): void
    {
        $company = $this->company();
        $remote = $this->company();
        $remote->forceFill(['name' => 'Azienda <b>rilevata</b>'])->save();
        $pc = $this->computer($company, [
            'gravityzone_id' => 'remote-private-identifier', 'gravityzone_company_id' => $remote->id,
            'technical_name' => 'PC-GZ', 'platform' => 'Windows 11 Pro', 'last_seen_at' => '2026-11-03 17:18:00',
        ]);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('edit')->table($pc));
        $modal = Blade::render('<x-filament::modal :heading="$heading" />', ['heading' => $page->instance()->getMountedActions()[0]->getModalHeading()]);
        $this->assertStringContainsString('aria-label="Ultimo Rilevamento: 03/11/2026 17:18"', $modal);
        $header = $page->instance()->getMountedActions()[0]->getModalHeading()->toHtml();
        $this->assertStringContainsString(e($remote->name), $header);
        $this->assertStringNotContainsString($remote->name, $header);
        $this->assertStringContainsString('Ultimo Rilevamento: 03/11/2026 17:18', $header);
        $html = $page->instance()->mountedActionSchema0->toHtml();
        $this->assertStringContainsString('Windows 11 Pro', $html);
        $this->assertStringNotContainsString('remote-private-identifier', $html);
        $this->assertStringNotContainsString('Dettagli GravityZone', $html);

        $manual = $this->computer($company);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('edit')->table($manual));
        $header = $page->instance()->getMountedActions()[0]->getModalHeading()->toHtml();
        $this->assertStringContainsString($company->name, $header);
        $this->assertStringNotContainsString('Ultimo Rilevamento', $header);
        $pc->forceFill(['last_seen_at' => null])->save();
        $this->assertStringContainsString('Ultimo Rilevamento: Non disponibile', view('filament.computer-heading', ['computer' => $pc])->render());
        Http::assertNothingSent();
    }

    public function test_invalid_dates_and_amounts_fail_atomically_and_show_field_errors(): void
    {
        $company = $this->company();
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->callAction('create', data: [
            'name' => 'Non salvare', 'activated_on' => '2026-05-01', 'expires_on' => '2026-04-30',
        ])->assertHasFormErrors(['expires_on']);
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->callAction('create', data: [
            'name' => 'Non salvare', 'amount' => '12.345',
        ])->assertHasFormErrors(['amount']);
        $this->assertDatabaseCount('computers', 0);
    }

    public function test_contextual_reconciliation_and_transfer_preserve_dates_and_visible_history(): void
    {
        $company = $this->company();
        $manual = $this->computer($company, ['activated_on' => '2026-04-20', 'expires_on' => '2027-04-20', 'price_cents' => 7990]);
        $imported = $this->computer($company, ['name' => null, 'technical_name' => 'PC-GZ', 'gravityzone_id' => 'id-verificato', 'gravityzone_company_id' => $company->id]);
        $replacement = $this->computer($company, ['name' => 'Sostitutivo']);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id);
        $page->callAction(TestAction::make('reconcile')->table($manual), data: ['target_id' => $imported->id])->assertHasNoFormErrors();
        $this->assertDatabaseCount('computers', 2);
        $page->callAction(TestAction::make('transfer')->table($manual), data: ['target_id' => $replacement->id])->assertHasNoFormErrors();
        $this->assertNull($manual->refresh()->expires_on);
        $this->assertSame('2027-04-20', $replacement->refresh()->expires_on->toDateString());
        $this->assertSame(7990, $replacement->price_cents);
        $page->mountAction(TestAction::make('edit')->table($manual));
        $html = $page->instance()->mountedActionSchema0->toHtml();
        $this->assertStringContainsString('Sostitutivo', $html);
        $this->assertStringContainsString('20/04/2027', $html);
        Http::assertNothingSent();
    }

    public function test_stale_forms_and_foreign_or_occupied_transfer_targets_do_not_overwrite_data(): void
    {
        $company = $this->company();
        $pc = $this->computer($company, ['activated_on' => '2026-04-20', 'expires_on' => '2027-04-20']);
        $foreign = $this->computer($this->company());
        $occupied = $this->computer($company, ['product' => 'Già compilato']);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)->mountAction(TestAction::make('edit')->table($pc));
        $pc->updateCommercial(['notes' => 'Aggiornata da altro operatore'], 0);
        $page->fillForm(['notes' => 'Non salvare'])->callMountedAction()->assertHasFormErrors(['activated_on']);
        $this->assertSame('Aggiornata da altro operatore', $pc->refresh()->notes);
        foreach ([$foreign, $occupied] as $target) {
            Livewire::test(Licenze::class)->set('data.company_id', $company->id)
                ->callAction(TestAction::make('transfer')->table($pc), data: ['target_id' => $target->id])->assertHasFormErrors(['target_id']);
        }
        $this->assertNotNull($pc->refresh()->expires_on);
    }

    public function test_replacement_choices_exclude_commercial_data_and_recheck_a_target_changed_after_opening(): void
    {
        $company = $this->company();
        $source = $this->computer($company, ['activated_on' => '2026-04-20', 'expires_on' => '2027-04-20']);
        $available = $this->computer($company, ['notes' => 'Note da conservare']);
        $this->computer($this->company());
        foreach (['product' => 'Prodotto', 'price_cents' => 0, 'commercial_description' => 'Descrizione', 'invoice_reference' => 'FT-1', 'quote_reference' => 'PR-1', 'expires_on' => '2027-01-01'] as $field => $value) {
            $this->computer($company, [$field => $value]);
        }
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('transfer')->table($source))
            ->assertFormFieldExists('target_id', fn (Select $field): bool => array_keys($field->getOptions()) === [$available->id])
            ->fillForm(['target_id' => $available->id]);
        $available->updateCommercial(['amount' => '0'], 0);
        $page->callMountedAction()->assertHasFormErrors(['target_id']);
        $this->assertSame('2027-04-20', $source->refresh()->expires_on->toDateString());
        $this->assertNull($available->refresh()->expires_on);
        $this->assertSame(0, $available->price_cents);
        $this->assertSame('Note da conservare', $available->notes);
    }

    public function test_reconciliation_choices_exclude_imports_with_local_data_history_or_absent_company(): void
    {
        $company = $this->company();
        $manual = $this->computer($company);
        $remote = ['name' => null, 'gravityzone_company_id' => $company->id, 'technical_name' => 'PC rilevato'];
        $available = $this->computer($company, $remote + ['gravityzone_id' => 'available']);
        foreach ([['product' => 'Prodotto'], ['price_cents' => 0], ['notes' => 'Nota'], ['name' => 'Nome locale'], ['history' => [['event' => 'updated']]], ['is_present' => false], ['gravityzone_company_id' => $this->company()->id]] as $index => $attributes) {
            $this->computer($company, array_replace($remote, ['gravityzone_id' => 'excluded-'.$index], $attributes));
        }
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('reconcile')->table($manual))
            ->assertFormFieldExists('target_id', fn (Select $field): bool => array_keys($field->getOptions()) === [$available->id]);
        $company->forceFill(['is_present' => false])->save();
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('reconcile')->table($manual))
            ->assertFormFieldExists('target_id', fn (Select $field): bool => $field->getOptions() === []);
    }

    public function test_history_shows_the_previous_commercial_description_as_escaped_text(): void
    {
        $company = $this->company();
        $description = 'Descrizione precedente <script>alert(1)</script>';
        $pc = $this->computer($company, ['commercial_description' => $description]);
        $pc->updateCommercial(['commercial_description' => 'Descrizione corrente'], 0);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('edit')->table($pc))->assertFormFieldDoesNotExist('commercial_description');
        $html = $page->instance()->mountedActionSchema0->toHtml();
        $this->assertStringContainsString(e($description), $html);
        $this->assertStringNotContainsString($description, $html);
        $page->set('mountedActions.0.data.commercial_description', 'Non salvare')
            ->fillForm(['notes' => 'Nota aggiornata'])->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame('Descrizione corrente', $pc->refresh()->commercial_description);
    }

    public function test_status_filter_handles_missing_dates_today_future_and_thirty_day_boundary(): void
    {
        $company = $this->company();
        $missing = $this->computer($company);
        $expired = $this->computer($company, ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-02']);
        $today = $this->computer($company, ['activated_on' => '2026-01-01', 'expires_on' => '2026-11-03']);
        $boundary = $this->computer($company, ['activated_on' => '2026-01-01', 'expires_on' => '2026-12-03']);
        $active = $this->computer($company, ['activated_on' => '2026-01-01', 'expires_on' => '2026-12-04']);
        $future = $this->computer($company, ['activated_on' => '2026-12-01', 'expires_on' => '2027-12-01']);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id);
        foreach (['missing' => [$missing], 'expired' => [$expired], 'expiring' => [$today, $boundary], 'active' => [$active], 'future' => [$future]] as $filter => $pcs) {
            $page->filterTable('status', $filter)->assertCanSeeTableRecords($pcs)->assertCountTableRecords(count($pcs));
        }
        $page->resetTableFilters()->searchTable($active->name)->assertCountTableRecords(1);
        Http::assertNothingSent();
        Mail::assertNothingSent();
    }

    public function test_missing_stale_and_cross_company_remote_data_remain_visible_without_secrets(): void
    {
        $company = $this->company();
        $company->forceFill(['technical_expires_on' => null, 'is_present' => false, 'endpoints_sync_error' => 'Lettura PC fallita.'])->save();
        $other = $this->company();
        $pc = $this->computer($company, ['gravityzone_id' => 'moved', 'gravityzone_company_id' => $other->id, 'is_present' => false]);
        GravityZoneSetting::create(['id' => 1, 'api_url' => 'https://example.test/api', 'api_key' => 'never-render-key'])
            ->forceFill(['endpoints_sync_error' => 'Import incompleto.'])->save();
        Livewire::test(Licenze::class)->set('data.company_id', $company->id)->assertCanSeeTableRecords([$pc])
            ->assertSee('Non disponibile')->assertSee('Azienda non più rilevata')->assertSee('Lettura PC fallita.')
            ->assertSee('Da verificare: rilevato in')->assertSee('Import incompleto.')->assertDontSee('never-render-key');
        Http::assertNothingSent();
    }

    public function test_disabling_user_blocks_an_already_open_edit_and_direct_access(): void
    {
        $company = $this->company();
        $pc = $this->computer($company);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)->mountAction(TestAction::make('edit')->table($pc));
        $user = auth()->user();
        $user->update(['is_active' => false]);
        $this->actingAs($user->fresh());
        $page->callMountedAction()->assertForbidden();
        $this->get('/admin')->assertForbidden();
        $this->assertNull($pc->refresh()->expires_on);
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-'.Company::count(), 'name' => 'Azienda '.Company::count(), 'last_seen_at' => now(), 'technical_expires_on' => '2026-12-31'])->save();

        return $company;
    }

    private function computer(Company $company, array $data = []): Computer
    {
        $pc = new Computer;
        $pc->forceFill(array_replace(['company_id' => $company->id, 'name' => 'PC '.Computer::count(), 'is_present' => true], $data))->save();

        return $pc->refresh();
    }
}
