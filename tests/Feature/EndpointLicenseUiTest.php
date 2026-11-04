<?php

namespace Tests\Feature;

use App\Filament\Pages\Licenze;
use App\Models\Company;
use App\Models\Computer;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class EndpointLicenseUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        $this->travelTo(new \DateTimeImmutable('2026-11-04 12:00 Europe/Rome'));
        Http::preventStrayRequests();
    }

    public function test_documented_states_and_unknowns_are_independent_of_commercial_and_company_dates(): void
    {
        $company = $this->company();
        $company->forceFill(['is_suspended' => true, 'technical_expires_on' => '2026-01-01'])->save();
        $computer = $this->remote($company, ['activated_on' => '2026-01-01', 'expires_on' => '2026-01-02']);
        foreach ([
            [0, 'Autenticazione in attesa', 'warning'], [1, 'Licenza attiva', 'success'],
            [2, 'Licenza Scaduta', 'danger'], [6, 'Assente / non applicabile', 'gray'],
            [null, 'Non disponibile', 'gray'], [99, 'Non disponibile', 'gray'],
        ] as [$status, $label, $color]) {
            $computer->gravityzone_license_status = $status;
            $display = $computer->gravityzoneLicenseDisplay();
            $this->assertSame($label, $display['label']);
            $this->assertSame($color, $display['color']);
            $this->assertSame('Letto il 04/11/2026 11:00.', $display['description']);
            $this->assertSame('Scaduta', $computer->commercialStatus());
        }
        $computer->forceFill(['gravityzone_license_status' => 1, 'license_checked_at' => null]);
        $this->assertSame('Non disponibile', $computer->gravityzoneLicenseDisplay()['label']);
        $computer->is_managed = null;
        $this->assertSame('Non disponibile', $computer->gravityzoneLicenseDisplay()['label']);
        $computer->is_managed = false;
        $this->assertSame('Non gestito', $computer->gravityzoneLicenseDisplay()['label']);
        $computer->gravityzone_id = null;
        $this->assertSame('Non collegato', $computer->gravityzoneLicenseDisplay()['label']);
    }

    public function test_absence_failed_reads_and_inaccessible_company_never_show_a_fresh_green_license(): void
    {
        $company = $this->company();
        $computer = $this->remote($company);
        foreach ([
            [['is_present' => false], [], 'Computer non più rilevato.'],
            [['license_sync_error' => 'Errore sanificato'], [], 'Ultima lettura della licenza non riuscita.'],
            [[], ['is_present' => false], 'Azienda rilevata non più disponibile.'],
            [[], ['endpoints_sync_error' => 'Inventario incompleto'], 'Ultima sincronizzazione dei computer incompleta.'],
            [[], ['gravityzone_type' => 0, 'can_manage_security' => false], 'Gestione della sicurezza non condivisa.'],
        ] as [$computerChanges, $companyChanges, $reason]) {
            $computer->refresh()->forceFill($computerChanges);
            $computer->setRelation('gravityzoneCompany', $company->fresh()->forceFill($companyChanges));
            $display = $computer->gravityzoneLicenseDisplay();
            $this->assertSame('Ultimo stato: Licenza attiva', $display['label']);
            $this->assertSame('gray', $display['color']);
            $this->assertStringContainsString('Dato non aggiornato. '.$reason, $display['description']);
            $this->assertStringContainsString('04/11/2026 11:00', $display['description']);
        }
        $computer->setRelation('gravityzoneCompany', null);
        $this->assertSame('gray', $computer->gravityzoneLicenseDisplay()['color']);
        Http::assertNothingSent();
    }

    public function test_another_endpoints_failure_does_not_hide_a_successfully_refreshed_license(): void
    {
        $company = $this->company();
        $company->forceFill(['endpoints_sync_error' => 'Errore su un altro computer.', 'endpoints_sync_attempted_at' => '2026-11-04 11:00:00'])->save();
        $computer = $this->remote($company);
        $this->assertSame('Licenza attiva', $computer->gravityzoneLicenseDisplay()['label']);
        $this->assertSame('success', $computer->gravityzoneLicenseDisplay()['color']);

        $computer->license_sync_error = 'Lettura non riuscita.';
        $this->assertSame('gray', $computer->gravityzoneLicenseDisplay()['color']);
        $computer->license_sync_error = null;
        $computer->gravityzoneCompany->endpoints_sync_attempted_at = '2026-11-04 11:01:00';
        $this->assertSame('Ultimo stato: Licenza attiva', $computer->gravityzoneLicenseDisplay()['label']);
        $this->assertSame('gray', $computer->gravityzoneLicenseDisplay()['color']);
        $computer->license_checked_at = null;
        $this->assertSame('Non disponibile', $computer->gravityzoneLicenseDisplay()['label']);
        Http::assertNothingSent();
    }

    public function test_table_and_modal_show_remote_status_without_http_or_allowing_payload_tampering(): void
    {
        $company = $this->company();
        $computer = $this->remote($company);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->assertTableColumnStateSet('gravityzone_license_status', 'Licenza attiva', $computer)
            ->assertSee('Stato licenza GZ')->assertSee('04/11/2026 11:00')->assertDontSee('Letto il')
            ->assertDontSee('Da completare · GravityZone')
            ->mountAction(TestAction::make('edit')->table($computer))
            ->assertFormFieldDoesNotExist('gravityzone_license_status')
            ->assertFormFieldDoesNotExist('license_checked_at');
        $modal = $page->instance()->mountedActionSchema0->toHtml();
        $this->assertStringContainsString('Stato licenza GravityZone', $modal);
        $this->assertStringContainsString('Licenza attiva', $modal);
        $this->assertStringContainsString('Stato tecnico indipendente dalle date commerciali.', $modal);
        $this->assertStringContainsString('non certifica che tutti i moduli di protezione siano attivi', $modal);
        $page->set('mountedActions.0.data.gravityzone_license_status', 2)
            ->set('mountedActions.0.data.license_checked_at', '2030-01-01 00:00:00')
            ->set('mountedActions.0.data.license_sync_error', 'MANOMESSO')
            ->fillForm(['notes' => 'Nota locale'])->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame(1, $computer->refresh()->gravityzone_license_status);
        $this->assertSame('2026-11-04 11:00:00', $computer->license_checked_at->format('Y-m-d H:i:s'));
        $this->assertNull($computer->license_sync_error);
        $this->assertSame('Nota locale', $computer->notes);
        Http::assertNothingSent();
    }

    public function test_reconciliation_keeps_remote_license_but_commercial_transfer_does_not_move_it(): void
    {
        $company = $this->company();
        $manual = Computer::create(['company_id' => $company->id, 'name' => 'PC manuale']);
        $manual->updateCommercial(['activated_on' => '2026-01-01', 'expires_on' => '2027-01-01'], 0);
        $remote = $this->remote($company, ['license_sync_error' => 'Lettura non riuscita.']);
        $remoteFields = ['gravityzone_license_status', 'license_checked_at', 'license_sync_error'];
        $expected = $remote->only($remoteFields);
        $manual->reconcileWith($remote, 1);
        $this->assertEquals($expected, $manual->only($remoteFields));
        $replacement = $this->remote($company, ['gravityzone_id' => 'replacement-endpoint', 'gravityzone_license_status' => 2, 'license_checked_at' => '2026-11-03 09:00:00']);
        $replacementExpected = $replacement->only($remoteFields);
        $manual->transferTo($replacement, 2);
        $this->assertEquals($expected, $manual->only($remoteFields));
        $this->assertEquals($replacementExpected, $replacement->only($remoteFields));
        $this->assertNull($manual->expires_on);
        $this->assertSame('2027-01-01', $replacement->expires_on->toDateString());
        Http::assertNothingSent();
    }

    public function test_auto_activation_is_editable_and_imports_remain_available_for_reconciliation(): void
    {
        $company = $this->company();
        $remote = $this->remote($company, ['activated_on' => '2026-08-15', 'first_seen_on' => '2026-08-15', 'activation_from_first_seen' => true]);
        $manual = Computer::create(['company_id' => $company->id, 'name' => 'PC manuale']);
        $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id)
            ->mountAction(TestAction::make('edit')->table($remote))
            ->assertSchemaStateSet(['activated_on' => '2026-08-15', 'expires_on' => null]);
        $this->assertStringContainsString('Primo rilevamento GravityZone', $page->instance()->mountedActionSchema0->toHtml());
        $page->unmountAction()->mountAction(TestAction::make('reconcile')->table($manual))
            ->assertFormFieldExists('target_id', fn ($field) => array_key_exists($remote->id, $field->getOptions()))
            ->fillForm(['target_id' => $remote->id])->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame('2026-08-15', $manual->refresh()->activated_on->toDateString());
        $this->assertTrue($manual->activation_from_first_seen);
        $this->assertSame('2026-08-15', $manual->first_seen_on->toDateString());
        $page->mountAction(TestAction::make('edit')->table($manual))
            ->fillForm(['activated_on' => '2026-07-01'])->callMountedAction()->assertHasNoFormErrors();
        $this->assertSame('2026-07-01', $manual->refresh()->activated_on->toDateString());
        $this->assertFalse($manual->activation_from_first_seen);
        Http::assertNothingSent();
    }

    public function test_company_hero_warns_only_for_upcoming_technical_expiry_with_suspension_taking_priority(): void
    {
        $company = $this->company();
        $this->remote($company, ['expires_on' => '2026-11-20']);

        foreach ([
            [null, false, false],
            ['2026-11-03', false, false],
            ['2026-11-04', false, true],
            ['2026-12-04', false, true],
            ['2026-12-05', false, false],
            ['2026-11-20', true, false],
        ] as [$expiry, $suspended, $expiring]) {
            $company->forceFill(['technical_expires_on' => $expiry, 'is_suspended' => $suspended])->save();
            $page = Livewire::test(Licenze::class)->set('data.company_id', $company->id);
            $html = $page->html();
            $this->assertSame($expiring, str_contains($html, 'bl-company-hero-expiring'));
            $this->assertSame($suspended, str_contains($html, 'bl-company-hero-suspended'));
            $this->assertDoesNotMatchRegularExpression('/fi-badge-label[^>]*>\\s*In scadenza\\s*</', $html);
        }

        Http::assertNothingSent();
    }

    private function company(): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => 'company-license', 'name' => 'Azienda licenze', 'is_present' => true, 'last_seen_at' => now()])->save();

        return $company;
    }

    private function remote(Company $company, array $attributes = []): Computer
    {
        $computer = new Computer;
        $computer->forceFill(array_replace([
            'company_id' => $company->id, 'gravityzone_company_id' => $company->id,
            'gravityzone_id' => 'endpoint-license-'.Computer::max('id'), 'technical_name' => 'PC gestito',
            'is_present' => true, 'is_managed' => true,
            'gravityzone_license_status' => 1, 'license_checked_at' => '2026-11-04 11:00:00',
        ], $attributes))->save();

        return $computer->refresh();
    }
}
