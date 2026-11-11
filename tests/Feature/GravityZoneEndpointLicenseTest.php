<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\GravityZoneClient;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\GravityZoneSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GravityZoneEndpointLicenseTest extends TestCase
{
    private const API_URL = 'https://gravityzone.example.test/api';

    private const RPC_ID = '103d7b05-ec02-481b-9ed6-c07b97de2b7a';

    private const TEST_KEY = 'fake-endpoint-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_the_documented_endpoint_request_reads_only_the_license_code(): void
    {
        // Anonymized projection of the official example, verified 2026-11-04; no live response.
        // https://www.bitdefender.com/business/support/en/77211-128484-getmanagedendpointdetails.html
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/GravityZone/endpoint-license.json')), true, flags: JSON_THROW_ON_ERROR);
        $fixture['result']['futureField'] = self::TEST_KEY.' private-data';
        $fixture['result']['agent']['futureField'] = 'private-data';
        Http::fake(['*' => Http::response($fixture)]);

        $this->assertSame(1, $this->readLicense());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' &&
            $request->url() === self::API_URL.'/v1.0/jsonrpc/network' &&
            $request->hasHeader('Authorization', 'Basic '.base64_encode(self::TEST_KEY.':')) &&
            $request->data() === [
                'jsonrpc' => '2.0', 'id' => self::RPC_ID, 'method' => 'getManagedEndpointDetails',
                'params' => ['endpointId' => 'endpoint-1'],
            ]);
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_endpoints_are_read_in_paced_batches_of_eight_and_a_rate_limit_skips_later_batches(): void
    {
        $this->freezeTime();
        Http::fake(fn (Request $request) => $request['params']['endpointId'] === 'endpoint-10'
            ? Http::response(self::TEST_KEY, 429, ['Retry-After' => '30'])
            : Http::response(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => [
                'id' => $request['params']['endpointId'], 'companyId' => 'company-1', 'agent' => ['licensed' => 1],
            ]]));
        $ids = array_map(fn (int $n): string => "endpoint-{$n}", range(1, 20));

        $results = app(GravityZoneClient::class)->getManagedEndpointDetails(new GravityZoneSetting([
            'api_url' => self::API_URL, 'api_key' => self::TEST_KEY,
        ]), $ids, 'company-1');

        $this->assertSame($ids, array_keys($results));
        foreach ($results as $id => $result) {
            if (in_array($id, ['endpoint-10', 'endpoint-17', 'endpoint-18', 'endpoint-19', 'endpoint-20'], true)) {
                $this->assertInstanceOf(GravityZoneException::class, $result);
                $this->assertTrue($result->stopSynchronization);
                $this->assertStringNotContainsString(self::TEST_KEY, $result->getMessage());
            } else {
                $this->assertSame(1, $result, $id);
            }
        }
        // The second batch was already sent when endpoint-10 hit the limit; the third is never sent.
        Http::assertSentCount(16);
        Sleep::assertSequence([Sleep::for(1000)->milliseconds()]);
    }

    #[DataProvider('licenseStates')]
    public function test_only_documented_integer_codes_are_available(array $details, ?int $expected): void
    {
        $this->fakeDetails(['id' => 'endpoint-1', 'companyId' => 'company-1'] + $details);

        $this->assertSame($expected, $this->readLicense());
    }

    public static function licenseStates(): array
    {
        return [
            'pending' => [['agent' => ['licensed' => 0]], 0],
            'active' => [['agent' => ['licensed' => 1]], 1],
            'expired' => [['agent' => ['licensed' => 2]], 2],
            'absent or not applicable' => [['agent' => ['licensed' => 6]], 6],
            'unknown positive code' => [['agent' => ['licensed' => 99]], null],
            'unknown negative code' => [['agent' => ['licensed' => -1]], null],
            'missing agent' => [[], null],
            'null agent' => [['agent' => null], null],
            'missing license' => [['agent' => (object) []], null],
            'null license' => [['agent' => ['licensed' => null]], null],
        ];
    }

    #[DataProvider('invalidDetails')]
    public function test_wrong_identities_and_malformed_types_are_rejected_without_exposing_details(mixed $details): void
    {
        $this->fakeDetails($details);

        $this->assertFalse($this->assertSanitizedFailure()->stopSynchronization);
        Http::assertSentCount(1);
    }

    public static function invalidDetails(): array
    {
        $identity = ['id' => 'endpoint-1', 'companyId' => 'company-1'];

        return [
            'null result' => [null],
            'array result' => [[]],
            'scalar result' => ['private-data'],
            'missing endpoint' => [['companyId' => 'company-1']],
            'wrong endpoint' => [['id' => 'private-data', 'companyId' => 'company-1']],
            'missing company' => [['id' => 'endpoint-1']],
            'wrong company' => [['id' => 'endpoint-1', 'companyId' => 'private-data']],
            'array agent' => [$identity + ['agent' => []]],
            'scalar agent' => [$identity + ['agent' => 'private-data']],
            'boolean license' => [$identity + ['agent' => ['licensed' => true]]],
            'string license' => [$identity + ['agent' => ['licensed' => '1']]],
            'float license' => [$identity + ['agent' => ['licensed' => 1.5]]],
            'array license' => [$identity + ['agent' => ['licensed' => []]]],
            'object license' => [$identity + ['agent' => ['licensed' => (object) []]]],
        ];
    }

    public function test_invalid_endpoint_and_company_ids_never_send_a_request(): void
    {
        foreach (['', ' ', str_repeat('a', 256)] as $invalidId) {
            foreach ([[$invalidId, 'company-1'], ['endpoint-1', $invalidId]] as [$endpointId, $companyId]) {
                try {
                    $this->readLicense($endpointId, $companyId);
                    $this->fail('Un ID non valido deve essere rifiutato.');
                } catch (GravityZoneException $exception) {
                    $this->assertStringContainsString('non valido', $exception->getMessage());
                }
            }
        }

        Http::assertNothingSent();
    }

    #[DataProvider('httpErrors')]
    public function test_http_failures_stop_synchronization_and_hide_the_response(int $status): void
    {
        Http::fake(['*' => Http::response(self::TEST_KEY.' private-data', $status)]);

        $this->assertTrue($this->assertSanitizedFailure()->stopSynchronization);
        Http::assertSentCount(1);
    }

    public static function httpErrors(): array
    {
        return [[401], [403], [500]];
    }

    public function test_rpc_errors_are_sanitized_and_do_not_return_a_license(): void
    {
        Http::fake(['*' => Http::response([
            'id' => self::RPC_ID, 'jsonrpc' => '2.0',
            'error' => ['code' => -32602, 'message' => self::TEST_KEY.' private-data'],
        ])]);

        $this->assertStringContainsString('rifiutato', $this->assertSanitizedFailure()->getMessage());
        Http::assertSentCount(1);
    }

    public function test_network_failures_stop_synchronization_without_exposing_the_cause(): void
    {
        Http::fake(fn () => throw new ConnectionException(self::TEST_KEY.' private-data'));

        $this->assertTrue($this->assertSanitizedFailure()->stopSynchronization);
    }

    public function test_retry_after_blocks_subsequent_details_requests_without_retrying(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-11-04 12:00:00 UTC'));
        Http::fakeSequence()
            ->push(self::TEST_KEY.' private-data', 429, ['Retry-After' => '90'])
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => [
                'id' => 'endpoint-1', 'companyId' => 'company-1', 'agent' => ['licensed' => 2],
            ]]);

        $this->assertTrue($this->assertSanitizedFailure()->stopSynchronization);
        $this->assertStringContainsString('tra 90 secondi', $this->assertSanitizedFailure()->getMessage());
        Http::assertSentCount(1);

        $this->travel(90)->seconds();
        $this->assertSame(2, $this->readLicense());
        Http::assertSentCount(2);
    }

    private function fakeDetails(mixed $details): void
    {
        Http::fake(['*' => Http::response(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => $details])]);
    }

    private function readLicense(string $endpointId = 'endpoint-1', string $companyId = 'company-1'): ?int
    {
        $result = app(GravityZoneClient::class)->getManagedEndpointDetails(new GravityZoneSetting([
            'api_url' => self::API_URL, 'api_key' => self::TEST_KEY,
        ]), [$endpointId], $companyId)[$endpointId];

        return $result instanceof GravityZoneException ? throw $result : $result;
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
