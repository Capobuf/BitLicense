<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\CompanySynchronizer;
use App\Integrations\GravityZone\EndpointSynchronizer;
use App\Integrations\GravityZone\GravityZoneClient;
use App\Models\Company;
use App\Models\CoverageAssignment;
use App\Models\Endpoint;
use App\Models\GravityZoneSetting;
use App\Models\Workstation;
use App\Services\CoverageService;
use App\Support\Euro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommercialDetailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    #[DataProvider('validAmounts')]
    public function test_euro_conversion_keeps_cents_exact_and_formats_two_decimals(string $input, int $cents, string $formatted): void
    {
        $this->assertSame($cents, Euro::toCents($input));
        $this->assertSame($formatted, Euro::fromCents($cents));
    }

    public static function validAmounts(): array
    {
        return [
            ['0.00', 0, '0.00'], ['0.10', 10, '0.10'], ['79,90', 7990, '79.90'],
            ['1.2', 120, '1.20'], ['42', 4200, '42.00'], ['999999999.99', 99999999999, '999999999.99'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_euro_rejects_invalid_prices_before_conversion(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Euro::toCents($amount);
    }

    public static function invalidAmounts(): array
    {
        return [['-1'], ['1e2'], ['1.000,00'], ['1,000'], ['0.001'], [' 1.00'], ["1.00\n"], ['1000000000.00'], ['']];
    }

    public function test_negative_cents_cannot_be_formatted_as_a_price(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Euro::fromCents(-1);
    }

    public function test_manual_details_product_prices_and_coverage_remain_independent_of_later_gravityzone_sync(): void
    {
        $company = $this->company('company-1');
        $product = $company->products()->create(['name' => 'Suite esempio con modulo aggiuntivo', 'annual_price_cents' => 9000]);
        $workstation = Workstation::create([
            'company_id' => $company->id, 'name' => 'Nome commerciale locale', 'company_product_id' => $product->id,
            'commercial_description' => 'Postazione amministrativa con add-on incluso',
            'installed_on' => '2026-05-02', 'notes' => 'Note locali',
        ]);
        $this->assertNull($workstation->endpoint_id);
        $this->assertDatabaseCount('coverages', 0);
        $this->assertSame($product->id, $workstation->companyProduct->id);
        $this->assertSame('2026-05-02', $workstation->installed_on->toDateString());
        $coverage = app(CoverageService::class)->create([
            'company_id' => $company->id, 'workstation_ids' => [$workstation->id],
            'starts_on' => '2026-04-20', 'ends_on' => '2027-04-20', 'amount' => '75.00',
            'invoice_reference' => 'FAT-2026-1', 'quote_reference' => 'PREV-2026-1',
        ])->sole();
        $purchased = $coverage->fresh()->toArray();
        $assignments = CoverageAssignment::all()->toArray();
        $product->update(['annual_price_cents' => 15000]);
        GravityZoneSetting::create(['id' => 1, 'api_url' => 'https://gravityzone.example.test/api', 'api_key' => 'fake-test-key']);
        $item = ['id' => 'endpoint-1', 'name' => 'PC-API', 'platform' => 'Windows', 'is_managed' => true, 'is_isolated' => false];
        $this->mock(GravityZoneClient::class, function (MockInterface $mock) use ($item): void {
            $mock->shouldReceive('getCompaniesList')->once()->andReturn([['id' => 'company-1', 'name' => 'Azienda rinominata da API']]);
            $mock->shouldReceive('getLicenseInfo')->once()->andReturn(['technical_license_type' => 'Mensile', 'technical_expires_on' => null]);
            $mock->shouldReceive('getNetworkInventoryItems')->times(2)->andReturn([$item], [array_replace($item, ['name' => 'PC-API-RINOMINATO'])]);
        });
        $sync = app(EndpointSynchronizer::class);
        $sync->sync();
        $workstation->linkEndpoint(Endpoint::sole());
        app(CompanySynchronizer::class)->sync();
        $sync->sync();

        $workstation->refresh();
        $this->assertSame('PC-API-RINOMINATO', $workstation->endpoint->name);
        $this->assertSame('Nome commerciale locale', $workstation->name);
        $this->assertSame('Postazione amministrativa con add-on incluso', $workstation->commercial_description);
        $this->assertSame('2026-05-02', $workstation->installed_on->toDateString());
        $this->assertSame('Note locali', $workstation->notes);
        $this->assertSame($product->id, $workstation->company_product_id);
        $this->assertSame(15000, $product->refresh()->annual_price_cents);
        $this->assertSame('Suite esempio con modulo aggiuntivo', $product->name);
        $this->assertSame($purchased, $coverage->refresh()->toArray());
        $this->assertSame($assignments, CoverageAssignment::all()->toArray());
        $this->assertNull($company->refresh()->technical_expires_on);
        Http::assertNothingSent();
    }

    public function test_products_belong_to_the_relationship_company_and_cannot_change_owner_through_mass_assignment(): void
    {
        $company = $this->company('company-1');
        $other = $this->company('company-2');
        $product = $company->products()->create(['name' => 'Prodotto locale', 'annual_price_cents' => 1000, 'company_id' => $other->id]);
        $product->update(['company_id' => $other->id, 'annual_price_cents' => 1234]);

        $this->assertSame($company->id, $product->fresh()->company_id);
        $this->assertSame($company->id, $product->fresh()->company->id);
        $this->assertSame(1234, $company->products()->sole()->annual_price_cents);
    }

    public function test_foreign_products_and_company_changes_cannot_break_workstation_ownership(): void
    {
        $company = $this->company('company-1');
        $other = $this->company('company-2');
        $own = $company->products()->create(['name' => 'Prodotto locale', 'annual_price_cents' => 1000]);
        $foreign = $other->products()->create(['name' => 'Altro prodotto', 'annual_price_cents' => 2000]);
        $workstation = Workstation::create(['company_id' => $company->id, 'name' => 'PC locale']);

        foreach ([['company_product_id' => $foreign->id], ['company_product_id' => 999999]] as $changes) {
            $this->assertInvalidProduct(fn () => $workstation->refresh()->update($changes));
            $this->assertNull($workstation->fresh()->company_product_id);
        }
        $workstation->refresh()->update(['company_product_id' => $own->id]);
        $this->assertInvalidProduct(fn () => $workstation->update(['company_id' => $other->id]));
        $this->assertSame($company->id, $workstation->fresh()->company_id);
        $this->assertSame($own->id, $workstation->fresh()->company_product_id);
        $this->assertInvalidProduct(fn () => Workstation::create([
            'company_id' => $company->id, 'name' => 'Non salvare', 'company_product_id' => $foreign->id,
        ]));
        $this->assertDatabaseCount('workstations', 1);
        Http::assertNothingSent();
    }

    private function company(string $externalId): Company
    {
        $company = new Company;
        $company->forceFill(['gravityzone_id' => $externalId, 'name' => 'Azienda esempio', 'last_seen_at' => now(), 'technical_expires_on' => '2026-12-31'])->save();

        return $company;
    }

    private function assertInvalidProduct(callable $action): void
    {
        try {
            $action();
            $this->fail('Un prodotto di un’altra azienda non deve essere assegnabile.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_product_id', $exception->errors());
        }
    }
}
