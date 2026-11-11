<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\GravityZoneClient;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\GravityZoneSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GravityZoneLicenseTest extends TestCase
{
    private const API_URL = 'https://gravityzone.example.test/api';

    private const RPC_ID = '103d7b05-ec02-481b-9ed6-c07b97de2b7a';

    private const TEST_KEY = 'fake-api-key-for-tests-only';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_documented_yearly_fixture_is_read_through_licensing_without_returning_license_keys(): void
    {
        // Official getLicenseInfo yearly example; only RPC ID and license keys are replaced.
        $fixture = file_get_contents(base_path('tests/Fixtures/GravityZone/license-yearly.json'));
        Http::fake([self::API_URL.'/v1.0/jsonrpc/licensing' => Http::response($fixture)]);

        $this->assertSame([
            'technical_license_type' => 'Licenza',
            'technical_expires_on' => '2021-12-28',
            'technical_product' => null,
        ], $this->readLicense());

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' &&
            $request->url() === self::API_URL.'/v1.0/jsonrpc/licensing' &&
            $request->hasHeader('Authorization', 'Basic '.base64_encode(self::TEST_KEY.':')) &&
            $request->hasHeader('Accept', 'application/json') &&
            $request->data() === [
                'jsonrpc' => '2.0',
                'id' => self::RPC_ID,
                'method' => 'getLicenseInfo',
                'params' => ['companyId' => 'company-1'],
            ]
        );
        Http::assertSentCount(1);
    }

    public function test_the_documented_monthly_fixture_without_an_expiry_is_valid(): void
    {
        // Official monthly Endpoint Security example; only RPC ID is replaced.
        Http::fake(['*' => Http::response(file_get_contents(base_path('tests/Fixtures/GravityZone/license-monthly.json')))]);

        $this->assertSame(['technical_license_type' => 'Mensile', 'technical_expires_on' => null, 'technical_product' => null], $this->readLicense());
    }

    public function test_monthly_subscription_uses_the_verified_end_date_instead_of_license_expiry(): void
    {
        // Anonymized projection of fields and formats verified live on 2026-11-04.
        $fixture = file_get_contents(base_path('tests/Fixtures/GravityZone/license-monthly-end.json'));
        Http::fake(['*' => Http::response($fixture)]);

        $this->assertSame('2027-06-30', $this->readLicense()['technical_expires_on']);
        Http::assertSentCount(1);
    }

    public function test_missing_or_null_monthly_end_never_falls_back_to_license_expiry(): void
    {
        $license = ['subscriptionType' => 3, 'expiryDate' => '2032-08-20T02:00:00'];
        Http::fakeSequence()->push($this->envelope($license))
            ->push($this->envelope($license + ['endSubscription' => null]));

        $this->assertNull($this->readLicense()['technical_expires_on']);
        $this->assertNull($this->readLicense()['technical_expires_on']);
        Http::assertSentCount(2);
    }

    public function test_monthly_end_accepts_a_real_leap_day(): void
    {
        Http::fake(['*' => Http::response($this->envelope([
            'subscriptionType' => 3, 'endSubscription' => '2028-02-29',
        ]))]);

        $this->assertSame('2028-02-29', $this->readLicense()['technical_expires_on']);
    }

    public function test_only_the_main_license_calendar_date_is_used_without_timezone_conversion(): void
    {
        Http::fake(['*' => Http::response($this->envelope([
            'subscriptionType' => 2,
            'expiryDate' => '2028-02-29T23:59:59',
            'endSubscription' => '2027-06-30',
            'additionalLicenses' => [['expiryDate' => '2030-01-01T00:00:00', 'licenseKey' => 'private-data']],
        ]))]);

        $this->assertSame(['technical_license_type' => 'Licenza', 'technical_expires_on' => '2028-02-29', 'technical_product' => null], $this->readLicense());
    }

    public function test_an_unknown_remote_timezone_does_not_apply_italian_daylight_saving_rules(): void
    {
        Http::fake(['*' => Http::response($this->envelope([
            'subscriptionType' => 2,
            'expiryDate' => '2027-03-28T02:30:00',
        ]))]);

        $this->assertSame('2027-03-28', $this->readLicense()['technical_expires_on']);
    }

    #[DataProvider('subscriptionTypes')]
    public function test_subscription_types_allow_a_null_expiry(int $type, string $label): void
    {
        Http::fake(['*' => Http::response($this->envelope(['subscriptionType' => $type, 'expiryDate' => null]))]);

        $this->assertSame(['technical_license_type' => $label, 'technical_expires_on' => null, 'technical_product' => null], $this->readLicense());
    }

    #[DataProvider('companyProducts')]
    public function test_only_documented_assigned_company_products_and_models_are_used(array $license, ?string $label): void
    {
        // Values and availability conditions from the official getLicenseInfo contract.
        Http::fake(['*' => Http::response($this->envelope($license + [
            'subscriptionType' => 3, 'additionalProductTypes' => [3, 5],
            'additionalProtectionModels' => ['mspSecureExtra'], 'licenseKey' => 'private-data',
        ]))]);

        $this->assertSame($label, $this->readLicense()['technical_product']);
    }

    public static function companyProducts(): array
    {
        return [
            'missing' => [[], null],
            'endpoint' => [['assignedProductType' => 0], 'Endpoint Security'],
            'edr' => [['assignedProductType' => 3], 'Bitdefender EDR'],
            'phasr' => [['assignedProductType' => 5], 'Bitdefender PHASR'],
            'a la carte' => [['assignedProductType' => 0, 'assignedProtectionModel' => 'aLaCarte'], 'Endpoint Security · À la carte'],
            'essentials' => [['assignedProductType' => 0, 'assignedProtectionModel' => 'mspSecureEssentials'], 'Endpoint Security · MSP Secure Essentials'],
            'secure' => [['assignedProductType' => 0, 'assignedProtectionModel' => 'mspSecure'], 'Endpoint Security · MSP Secure'],
            'plus' => [['assignedProductType' => 0, 'assignedProtectionModel' => 'mspSecurePlus'], 'Endpoint Security · MSP Secure Plus'],
            'extra' => [['assignedProductType' => 0, 'assignedProtectionModel' => 'mspSecureExtra'], 'Endpoint Security · MSP Secure Extra'],
            'unknown product' => [['assignedProductType' => 99, 'assignedProtectionModel' => 'mspSecure'], null],
            'unknown model' => [['assignedProductType' => 0, 'assignedProtectionModel' => 'private-data'], 'Endpoint Security'],
            'model without product' => [['assignedProtectionModel' => 'mspSecure'], null],
            'other product model' => [['assignedProductType' => 3, 'assignedProtectionModel' => 'mspSecure'], 'Bitdefender EDR'],
            'trial without model' => [['subscriptionType' => 5, 'assignedProductType' => 0], 'Endpoint Security'],
        ];
    }

    public static function subscriptionTypes(): array
    {
        return [[1, 'Prova'], [2, 'Licenza'], [3, 'Mensile'], [4, 'Licenza mensile di prova'], [5, 'Abbonamento mensile di prova'], [6, 'FRAT'], [99, 'Codice 99']];
    }

    #[DataProvider('invalidLicenses')]
    public function test_invalid_license_data_is_rejected_without_exposing_the_payload(mixed $result): void
    {
        Http::fake(['*' => Http::response($this->envelope($result))]);

        $this->assertSanitizedFailure();
        Http::assertSentCount(1);
    }

    public static function invalidLicenses(): array
    {
        $cases = [
            'null result' => [null],
            'list result' => [[]],
            'missing type' => [['licenseKey' => 'private-data']],
            'string type' => [['subscriptionType' => '2']],
            'boolean type' => [['subscriptionType' => true]],
            'string product type' => [['subscriptionType' => 3, 'assignedProductType' => '0']],
            'boolean product type' => [['subscriptionType' => 3, 'assignedProductType' => true]],
            'invalid protection model' => [['subscriptionType' => 3, 'assignedProtectionModel' => ['private-data']]],
        ];

        foreach (['2027-02-29T12:00:00', '2028-04-31T12:00:00', '2028-02-29T24:00:00', '2028-02-29', '2028-02-29T12:00:00Z', '2028-02-29T12:00:00+01:00', '', 'private-data', 123] as $index => $expiry) {
            $cases['invalid expiry '.$index] = [['subscriptionType' => 2, 'expiryDate' => $expiry]];
        }

        foreach (['2027-02-29', '2028-04-31', '2028-02-29T00:00:00', '2028-2-29', '', "2027-06-30\0", 'private-data', 123, true, []] as $index => $expiry) {
            $cases['invalid monthly end '.$index] = [['subscriptionType' => 3, 'endSubscription' => $expiry]];
        }

        return $cases;
    }

    public function test_invalid_company_ids_never_send_a_request(): void
    {
        foreach (['', '   ', str_repeat('a', 256)] as $companyId) {
            try {
                $this->readLicense($companyId);
                $this->fail('Un ID azienda non valido deve essere rifiutato.');
            } catch (GravityZoneException $exception) {
                $this->assertSame('ID azienda GravityZone non valido.', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_licensing_permission_errors_are_sanitized_for_http_and_rpc_responses(): void
    {
        Http::fakeSequence()
            ->push(self::TEST_KEY.' private-data', 403)
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'error' => [
                'code' => -32001, 'message' => self::TEST_KEY.' private-data',
            ]]);

        $this->assertTrue($this->assertSanitizedFailure()->stopSynchronization);
        $this->assertSanitizedFailure();
        Http::assertSentCount(2);
    }

    public function test_rate_limiting_stops_synchronization_without_retrying(): void
    {
        Http::fake(['*' => Http::response(self::TEST_KEY.' private-data', 429, ['Retry-After' => '30'])]);

        $exception = $this->assertSanitizedFailure();
        $this->assertTrue($exception->stopSynchronization);
        $this->assertStringContainsString('tra 30 secondi', $exception->getMessage());
        Http::assertSentCount(1);
    }

    public function test_a_network_failure_stops_synchronization_without_retrying_or_exposing_its_cause(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException(self::TEST_KEY.' private-data');
        });

        $this->assertTrue($this->assertSanitizedFailure()->stopSynchronization);
        $this->assertSame(1, $attempts);
    }

    private function readLicense(string $companyId = 'company-1'): array
    {
        $result = app(GravityZoneClient::class)->getLicenseInfo(new GravityZoneSetting([
            'api_url' => self::API_URL, 'api_key' => self::TEST_KEY,
        ]), [$companyId])[$companyId];

        return $result instanceof GravityZoneException ? throw $result : $result;
    }

    private function envelope(mixed $result): array
    {
        return ['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => $result];
    }

    private function assertSanitizedFailure(): GravityZoneException
    {
        try {
            $this->readLicense();
            $this->fail('La risposta non valida deve essere rifiutata.');
        } catch (GravityZoneException $exception) {
            $this->assertStringNotContainsString(self::TEST_KEY, $exception->getMessage());
            $this->assertStringNotContainsString('private-data', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_URL, $exception->getMessage());
            $this->assertNull($exception->getPrevious());

            return $exception;
        }
    }
}
