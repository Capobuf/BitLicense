<?php

namespace Tests\Feature;

use App\Integrations\GravityZone\GravityZoneClient;
use App\Integrations\GravityZone\GravityZoneException;
use App\Models\GravityZoneSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class GravityZoneClientTest extends TestCase
{
    private const API_URL = 'https://gravityzone.example.test/api';

    private const RPC_ID = '103d7b05-ec02-481b-9ed6-c07b97de2b7a';

    private const TEST_KEY = 'fake-api-key-for-tests-only';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_client_sends_only_the_documented_read_request_and_parses_the_fixture(): void
    {
        $fixture = file_get_contents(base_path('tests/Fixtures/GravityZone/companies-list.json'));
        Http::fake([self::API_URL.'/v1.0/jsonrpc/network' => Http::response($fixture)]);

        $companies = app(GravityZoneClient::class)->getCompaniesList($this->settings());

        $this->assertSame(json_decode($fixture, true)['result'], $companies);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' &&
            $request->url() === self::API_URL.'/v1.0/jsonrpc/network' &&
            $request->hasHeader('Authorization', 'Basic '.base64_encode(self::TEST_KEY.':')) &&
            $request->hasHeader('Accept', 'application/json') &&
            $request->data() === [
                'jsonrpc' => '2.0',
                'id' => self::RPC_ID,
                'method' => 'getCompaniesList',
                'params' => [],
            ]
        );
        Http::assertSentCount(1);

        $this->assertSame(['getCompaniesList', 'getCompanyDetails', 'getLicenseInfo', 'getNetworkInventoryItems', 'getManagedEndpointDetails'], GravityZoneClient::READ_ONLY_METHODS);
        $this->assertSame(['getCompaniesList', 'getCompanyDetails', 'getLicenseInfo', 'getNetworkInventoryItems', 'getManagedEndpointDetails'], array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(GravityZoneClient::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        ));
    }

    public function test_empty_company_lists_are_valid_and_extra_fields_are_not_exposed(): void
    {
        Http::fakeSequence()
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => []])
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => [
                ['id' => 'company-1', 'name' => 'Azienda esempio', 'extraPrivateField' => 'private-data'],
            ]]);

        $client = app(GravityZoneClient::class);
        $this->assertSame([], $client->getCompaniesList($this->settings()));
        $this->assertSame([['id' => 'company-1', 'name' => 'Azienda esempio']], $client->getCompaniesList($this->settings()));
        Http::assertSentCount(2);
    }

    public function test_company_details_read_only_the_documented_status_and_validate_the_company_identity(): void
    {
        // Projection of the official getCompanyDetails example, with an anonymized company ID.
        Http::fakeSequence()
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => ['id' => 'company-1', 'isSuspended' => false, 'address' => 'private-data']])
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => ['id' => 'company-1', 'isSuspended' => true]])
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => ['id' => 'company-1']])
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => ['id' => 'company-1', 'isSuspended' => null]]);

        $client = app(GravityZoneClient::class);
        foreach ([false, true, null, null] as $status) {
            $this->assertSame(['is_suspended' => $status, 'gravityzone_type' => null, 'can_manage_security' => null], $client->getCompanyDetails($this->settings(), 'company-1'));
        }
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' &&
            $request->url() === self::API_URL.'/v1.0/jsonrpc/companies' &&
            $request->hasHeader('Authorization', 'Basic '.base64_encode(self::TEST_KEY.':')) &&
            $request->data() === ['jsonrpc' => '2.0', 'id' => self::RPC_ID, 'method' => 'getCompanyDetails', 'params' => ['companyId' => 'company-1']]
        );
        Http::assertSentCount(4);
    }

    public function test_company_scope_fields_and_child_listing_use_verified_read_parameters(): void
    {
        // Types, sharing flags and parentId verified live on 2026-11-04; IDs/names are fictitious.
        Http::fakeSequence()
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => [
                'id' => 'partner-1', 'type' => 0, 'canBeManagedByAbove' => false, 'isSuspended' => false,
            ]])
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => [['id' => 'client-1', 'name' => 'Cliente esempio']]]);
        $client = app(GravityZoneClient::class);
        $this->assertSame(['is_suspended' => false, 'gravityzone_type' => 0, 'can_manage_security' => false],
            $client->getCompanyDetails($this->settings(), 'partner-1'));
        $this->assertSame([['id' => 'client-1', 'name' => 'Cliente esempio']], $client->getCompaniesList($this->settings(), 'partner-1'));
        Http::assertSent(fn (Request $request): bool => $request['method'] === 'getCompaniesList' && $request['params'] === ['parentId' => 'partner-1']);
    }

    public function test_invalid_parent_ids_never_send_a_request(): void
    {
        foreach (['', ' ', str_repeat('a', 256)] as $id) {
            try {
                app(GravityZoneClient::class)->getCompaniesList($this->settings(), $id);
                $this->fail('Un ID parent non valido deve essere rifiutato.');
            } catch (GravityZoneException $exception) {
                $this->assertSame('ID azienda GravityZone non valido.', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }

    #[DataProvider('invalidCompanyDetails')]
    public function test_company_details_reject_malformed_status_and_foreign_companies(mixed $details): void
    {
        Http::fake(['*' => Http::response(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => $details])]);

        $this->expectException(GravityZoneException::class);
        $this->expectExceptionMessage('stato aziendale valido');
        app(GravityZoneClient::class)->getCompanyDetails($this->settings(), 'company-1');
    }

    public static function invalidCompanyDetails(): array
    {
        return [
            'foreign company' => [['id' => 'company-2', 'isSuspended' => false]],
            'missing ID' => [['isSuspended' => false]],
            'string status' => [['id' => 'company-1', 'isSuspended' => 'false']],
            'numeric status' => [['id' => 'company-1', 'isSuspended' => 0]],
            'non-object' => [[]],
            'string company type' => [['id' => 'company-1', 'type' => '0']],
            'unknown company type' => [['id' => 'company-1', 'type' => 2]],
            'string sharing' => [['id' => 'company-1', 'canBeManagedByAbove' => 'false']],
        ];
    }

    #[DataProvider('invalidConfigurations')]
    public function test_invalid_configuration_is_rejected_without_sending_a_request(?string $url, ?string $key): void
    {
        try {
            app(GravityZoneClient::class)->getCompaniesList($this->settings($url, $key));
            $this->fail('La configurazione non valida deve essere rifiutata.');
        } catch (GravityZoneException $exception) {
            $this->assertStringContainsString('Configura', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        Http::assertNothingSent();
    }

    public static function invalidConfigurations(): array
    {
        return [
            'missing configuration' => [null, null],
            'missing key' => [self::API_URL, null],
            'blank key' => [self::API_URL, '   '],
            'missing URL' => [null, self::TEST_KEY],
            'HTTP' => ['http://gravityzone.example.test/api', self::TEST_KEY],
            'invalid URL' => ['https://', self::TEST_KEY],
            'wrong path' => ['https://gravityzone.example.test/console', self::TEST_KEY],
            'credentials in URL' => ['https://user:password@gravityzone.example.test/api', self::TEST_KEY],
            'query in URL' => [self::API_URL.'?secret=test', self::TEST_KEY],
            'fragment in URL' => [self::API_URL.'#secret', self::TEST_KEY],
        ];
    }

    public function test_an_unreadable_saved_key_is_rejected_without_a_request(): void
    {
        $settings = new GravityZoneSetting;
        $settings->setRawAttributes(['api_url' => self::API_URL, 'api_key' => 'invalid-encrypted-value']);

        try {
            app(GravityZoneClient::class)->getCompaniesList($settings);
            $this->fail('La chiave illeggibile deve essere rifiutata.');
        } catch (GravityZoneException $exception) {
            $this->assertStringContainsString('Inseriscila nuovamente', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        Http::assertNothingSent();
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_responses_and_rpc_errors_are_sanitized(string $body): void
    {
        Http::fake(['*' => Http::response($body)]);

        $this->assertSanitizedFailure();
        Http::assertSentCount(1);
    }

    public static function invalidResponses(): array
    {
        $envelope = ['id' => self::RPC_ID, 'jsonrpc' => '2.0'];

        return [
            'HTML' => ['<html>'.self::TEST_KEY.'</html>'],
            'invalid JSON' => ['{'.self::TEST_KEY],
            'scalar' => ['true'],
            'array envelope' => ['[]'],
            'wrong RPC version' => [json_encode(['id' => self::RPC_ID, 'jsonrpc' => '1.0', 'result' => []])],
            'wrong request ID' => [json_encode(['id' => 'wrong', 'jsonrpc' => '2.0', 'result' => []])],
            'missing result' => [json_encode($envelope)],
            'object result' => [json_encode($envelope + ['result' => new \stdClass])],
            'null result' => [json_encode($envelope + ['result' => null])],
            'company missing name' => [json_encode($envelope + ['result' => [['id' => 'company-1']]])],
            'company empty ID' => [json_encode($envelope + ['result' => [['id' => ' ', 'name' => 'Azienda']]])],
            'company numeric name' => [json_encode($envelope + ['result' => [['id' => 'company-1', 'name' => 123]]])],
            'scalar company' => [json_encode($envelope + ['result' => ['private-data']])],
            'RPC error with private message' => [json_encode($envelope + ['error' => ['code' => -32602, 'message' => self::TEST_KEY.' private-data']])],
            'result and error together' => [json_encode($envelope + ['error' => null, 'result' => []])],
        ];
    }

    #[DataProvider('httpFailures')]
    public function test_http_errors_are_sanitized_and_redirects_are_not_followed(int $status): void
    {
        Http::fake(['*' => Http::response(self::TEST_KEY.' private-data', $status, [
            'Location' => 'https://untrusted.example.test/'.self::TEST_KEY,
        ])]);

        $message = $this->assertSanitizedFailure();

        if (in_array($status, [401, 403], true)) {
            $this->assertStringContainsString('Accesso GravityZone negato', $message);
        }

        Http::assertSentCount(1);
    }

    public static function httpFailures(): array
    {
        return [[301], [302], [401], [403], [500], [503]];
    }

    public function test_network_errors_and_timeouts_do_not_expose_the_original_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('Network timeout '.self::TEST_KEY.' private-data'));

        $message = $this->assertSanitizedFailure();
        $this->assertStringContainsString('errore di rete o tempo di attesa scaduto', $message);
    }

    #[DataProvider('retryAfterHeaders')]
    public function test_rate_limiting_reports_retry_after_without_retrying(string $header, int $seconds): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-11-02 12:00:00 UTC'));
        Http::fake(['*' => Http::response(self::TEST_KEY, 429, ['Retry-After' => $header])]);

        $message = $this->assertSanitizedFailure();
        $this->assertStringContainsString("tra {$seconds} secondi", $message);
        Http::assertSentCount(1);
    }

    public static function retryAfterHeaders(): array
    {
        return [
            'seconds' => ['90', 90],
            'zero' => ['0', 0],
            'HTTP date' => ['Mon, 02 Nov 2026 12:01:30 GMT', 90],
            'past date' => ['Mon, 02 Nov 2026 11:59:30 GMT', 0],
            'missing header' => ['', 60],
            'invalid header' => ['private-data', 60],
            'invalid HTTP date' => ['Mon, 32 Nov 2026 12:01:30 GMT', 60],
        ];
    }

    #[DataProvider('retryAfterHeaders')]
    public function test_retry_after_blocks_all_read_methods_across_client_instances_until_it_expires(string $header, int $seconds): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-11-02 12:00:00 UTC'));
        Http::fakeSequence()
            ->push(self::TEST_KEY, 429, ['Retry-After' => $header])
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => []]);
        $this->assertSanitizedFailure();

        if ($seconds > 0) {
            $this->travel($seconds - 1)->seconds();
            foreach (GravityZoneClient::READ_ONLY_METHODS as $method) {
                try {
                    $arguments = $method === 'getManagedEndpointDetails' ? ['endpoint-1', 'company-1'] : ['company-1'];
                    (new GravityZoneClient)->{$method}($this->settings(), ...$arguments);
                    $this->fail('Nessuna lettura deve partire durante l’attesa condivisa.');
                } catch (GravityZoneException $exception) {
                    $this->assertTrue($exception->stopSynchronization);
                    $this->assertStringContainsString('Riprova tra 1', $exception->getMessage());
                }
            }
            Http::assertSentCount(1);
            $this->travel(1)->seconds();
        }

        $this->assertSame([], (new GravityZoneClient)->getCompaniesList($this->settings()));
        Http::assertSentCount(2);
    }

    public function test_a_trailing_slash_is_normalized_and_native_transport_safety_options_are_preserved(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(self::API_URL.'/v1.0/jsonrpc/network', $request->url());
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(20, $options['timeout']);
            $this->assertTrue($options['verify'] ?? true);

            return Http::response(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => []]);
        });

        $this->assertSame([], app(GravityZoneClient::class)->getCompaniesList($this->settings(self::API_URL.'/')));
    }

    private function settings(?string $url = self::API_URL, ?string $key = self::TEST_KEY): GravityZoneSetting
    {
        return new GravityZoneSetting(['api_url' => $url, 'api_key' => $key]);
    }

    private function assertSanitizedFailure(): string
    {
        try {
            app(GravityZoneClient::class)->getCompaniesList($this->settings());
            $this->fail('La risposta non valida deve essere rifiutata.');
        } catch (GravityZoneException $exception) {
            $this->assertStringNotContainsString(self::TEST_KEY, $exception->getMessage());
            $this->assertStringNotContainsString('private-data', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_URL, $exception->getMessage());
            $this->assertNull($exception->getPrevious());

            return $exception->getMessage();
        }
    }
}
