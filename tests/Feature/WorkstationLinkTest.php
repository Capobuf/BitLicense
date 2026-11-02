<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Endpoint;
use App\Models\Workstation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WorkstationLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_choice_distinguishes_identical_names_and_a_second_call_is_idempotent(): void
    {
        $company = $this->company('company-1');
        $endpoint = $this->endpoint($company, 'endpoint-1');
        $first = Workstation::create(['company_id' => $company->id, 'name' => 'PC omonimo']);
        $second = Workstation::create(['company_id' => $company->id, 'name' => 'PC omonimo', 'notes' => 'Scelta esplicita']);
        $this->assertNull($endpoint->workstation);
        $second->linkEndpoint($endpoint);
        $second->linkEndpoint($endpoint);
        $this->assertNull($first->refresh()->endpoint_id);
        $this->assertSame($endpoint->id, $second->refresh()->endpoint_id);
        $this->assertSame('Scelta esplicita', $second->notes);
        $this->assertDatabaseCount('workstations', 2);
    }

    public function test_cross_company_already_linked_and_absent_endpoints_are_rejected_without_changing_links(): void
    {
        $company = $this->company('company-1');
        $foreign = $this->company('company-2');
        $endpoint = $this->endpoint($company, 'endpoint-1');
        $otherEndpoint = $this->endpoint($company, 'endpoint-2');
        $local = Workstation::create(['company_id' => $company->id, 'name' => 'Prima']);
        $other = Workstation::create(['company_id' => $company->id, 'name' => 'Seconda']);
        $wrongCompany = Workstation::create(['company_id' => $foreign->id, 'name' => 'Altra azienda']);
        $this->assertRejected(fn () => $wrongCompany->linkEndpoint($endpoint));
        $local->linkEndpoint($endpoint);
        $this->assertRejected(fn () => $other->linkEndpoint($endpoint));
        $this->assertRejected(fn () => $local->linkEndpoint($otherEndpoint));
        $otherEndpoint->forceFill(['is_present' => false])->save();
        $this->assertRejected(fn () => $other->linkEndpoint($otherEndpoint));
        $this->assertSame($endpoint->id, $local->refresh()->endpoint_id);
        $this->assertNull($other->refresh()->endpoint_id);
        $this->assertNull($wrongCompany->refresh()->endpoint_id);
    }

    private function assertRejected(callable $action): void
    {
        try {
            $action();
            $this->fail('L’associazione non valida deve essere rifiutata.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('workstation_id', $exception->errors());
        }
    }

    private function company(string $id): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => $id, 'name' => 'Azienda esempio', 'last_seen_at' => now()])->save();

        return $company;
    }

    private function endpoint(Company $company, string $id): Endpoint
    {
        $endpoint = new Endpoint;
        $endpoint->forceFill(['company_id' => $company->id, 'gravityzone_id' => $id, 'name' => 'PC omonimo', 'last_seen_at' => now()])->save();

        return $endpoint;
    }
}
