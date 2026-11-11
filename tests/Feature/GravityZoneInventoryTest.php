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

class GravityZoneInventoryTest extends TestCase
{
    private const API_URL = 'https://gravityzone.example.test/api';

    private const RPC_ID = '103d7b05-ec02-481b-9ed6-c07b97de2b7a';

    private const TEST_KEY = 'fake-inventory-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_documented_ad_items_are_paginated_and_overlap_with_the_company_query_is_not_duplicated(): void
    {
        $this->freezeTime();
        // Projections of official getNetworkInventoryItems AD examples: anonymized, split into two test pages.
        $firstPage = $this->fixture('inventory-page-1');
        $secondPage = $this->fixture('inventory-page-2');
        Http::fakeSequence()
            ->push($this->fixture('inventory-ad-folders'))
            ->push($firstPage)->push($secondPage)
            ->push($this->page([...$firstPage['result']['items'], ...$secondPage['result']['items']]));

        $endpoints = $this->readInventory();

        $this->assertSame([
            ['id' => 'endpoint-1', 'name' => 'Postazione esempio 1', 'platform' => 'Windows Server 2019 Standard', 'is_managed' => true, 'is_isolated' => null, 'first_seen_on' => null],
            ['id' => 'endpoint-2', 'name' => 'Postazione esempio 2', 'platform' => 'Windows Server 2016 Standard', 'is_managed' => true, 'is_isolated' => null, 'first_seen_on' => null],
            ['id' => 'endpoint-3', 'name' => 'Postazione esempio 3', 'platform' => 'Linux Ubuntu 20.04.3 LTS', 'is_managed' => true, 'is_isolated' => null, 'first_seen_on' => null],
        ], $endpoints);
        Http::assertSentInOrder([
            fn (Request $request): bool => $request['params'] === [
                'parentId' => 'company-1', 'page' => 1, 'perPage' => 100,
                'filters' => ['details' => ['name' => 'Active Directory']],
            ],
            fn (Request $request): bool => $request['params'] === $this->endpointParameters('company-1', 1),
            fn (Request $request): bool => $request['params'] === $this->endpointParameters('company-1', 2),
            fn (Request $request): bool => $request['params'] === $this->endpointParameters('ad-folder-1', 1),
        ]);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL.'/v1.1/jsonrpc/network' &&
            $request->method() === 'POST' && $request->hasHeader('Accept', 'application/json') &&
            $request->hasHeader('Authorization', 'Basic '.base64_encode(self::TEST_KEY.':')) &&
            $request['method'] === 'getNetworkInventoryItems' && $request['jsonrpc'] === '2.0' && $request['id'] === self::RPC_ID);
        Http::assertSentCount(4);
        // Instant fake responses: every request after the first waits for the pacing interval.
        Sleep::assertSleptTimes(3);
    }

    public function test_ad_only_endpoints_are_read_and_discovery_selects_exact_ad_groups(): void
    {
        $folders = $this->fixture('inventory-ad-folders')['result']['items'];
        $folders[] = $this->item(['id' => 'prefix-match', 'name' => 'Active Directory archive', 'type' => 4]);
        $folders[] = $this->item(['id' => 'not-a-group', 'name' => 'Active Directory', 'type' => 5]);
        Http::fakeSequence()->push($this->page($folders))->push($this->page([]))->push($this->page([$this->item()]));

        $this->assertSame('endpoint-1', $this->readInventory()[0]['id']);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request['params']['parentId'] === 'ad-folder-1');
    }

    public function test_empty_inventory_is_valid_and_both_queries_are_paged_when_needed(): void
    {
        Http::fakeSequence()->push($this->page([$this->item(['id' => 'group-1', 'type' => 4])], 1, true))
            ->push($this->page([], 2))->push($this->page([]));

        $this->assertSame([], $this->readInventory());
        Http::assertSentCount(3);
    }

    public function test_computers_virtual_machines_and_ec2_keep_only_available_metadata_and_ignore_other_item_types(): void
    {
        $this->fakeInventory([
            $this->item(['details' => ['operatingSystemVersion' => 'Linux', 'isManaged' => false, 'isIsolated' => true,
                'ip' => 'private-data', 'macs' => ['private-data'], 'firstSeen' => '2026-08-15T10:30:00', 'futureField' => 'private-data']]),
            $this->item(['id' => 'vm-1', 'type' => 6]),
            $this->item(['id' => 'ec2-1', 'type' => 7, 'details' => ['operatingSystemVersion' => '', 'isManaged' => null, 'isIsolated' => null, 'firstSeen' => '']]),
            $this->item(['id' => 'group-1', 'type' => 4]),
        ]);

        $endpoints = $this->readInventory();

        $this->assertCount(3, $endpoints);
        $this->assertSame(['id' => 'endpoint-1', 'name' => 'Postazione di prova', 'platform' => 'Linux', 'is_managed' => false, 'is_isolated' => true, 'first_seen_on' => '2026-08-15'], $endpoints[0]);
        $this->assertNull($endpoints[1]['platform']);
        $this->assertNull($endpoints[1]['is_managed']);
        $this->assertNull($endpoints[1]['is_isolated']);
        $this->assertNull($endpoints[2]['platform']);
        $this->assertNull($endpoints[2]['first_seen_on']);
        $this->assertStringNotContainsString('private-data', json_encode($endpoints));
    }

    public function test_partner_inventory_ignores_only_explicitly_known_other_companies(): void
    {
        $this->fakeInventory([
            $this->item(['id' => 'own-endpoint']),
            $this->item(['id' => 'client-endpoint', 'companyId' => 'known-client']),
        ]);
        $items = $this->readInventory('company-1', ['known-client']);
        $this->assertSame(['own-endpoint'], array_column($items, 'id'));
    }

    public function test_unknown_companies_are_still_rejected_in_partner_inventory(): void
    {
        $this->fakeInventory([$this->item(['companyId' => 'unknown-client'])]);
        $this->expectException(GravityZoneException::class);
        $this->readInventory('company-1', ['known-client']);
    }

    #[DataProvider('invalidPages')]
    public function test_invalid_pages_fail_without_returning_a_partial_inventory(array $changes): void
    {
        $page = $this->page([]);
        $page['result'] = array_replace($page['result'], $changes);
        Http::fakeSequence()->push($this->page([]))->push($page);

        $this->assertSanitizedFailure();
        Http::assertSentCount(2);
    }

    public static function invalidPages(): array
    {
        return [
            'wrong page' => [['page' => 2]],
            'string page' => [['page' => '1']],
            'wrong page size' => [['perPage' => 30]],
            'missing continuation' => [['hasMoreRecords' => null]],
            'string continuation' => [['hasMoreRecords' => 'false']],
            'missing items' => [['items' => null]],
            'items object' => [['items' => (object) []]],
            'empty continuation page' => [['hasMoreRecords' => true]],
            'oversized page' => [['items' => array_fill(0, 101, ['id' => 'private-data'])]],
        ];
    }

    #[DataProvider('invalidItems')]
    public function test_malformed_items_or_different_companies_fail_even_when_the_item_is_not_a_computer(array $changes): void
    {
        $this->fakeInventory([$this->item($changes)]);

        $this->assertSanitizedFailure();
        Http::assertSentCount(2);
    }

    public static function invalidItems(): array
    {
        return [
            'blank ID' => [['id' => ' ']],
            'long ID' => [['id' => str_repeat('a', 256)]],
            'blank name' => [['name' => '']],
            'other company' => [['companyId' => 'private-data']],
            'other company group' => [['type' => 4, 'companyId' => 'private-data']],
            'missing company' => [['companyId' => null]],
            'string type' => [['type' => '5']],
            'invalid details' => [['details' => []]],
            'invalid platform' => [['details' => ['operatingSystemVersion' => 123]]],
            'invalid management flag' => [['details' => ['isManaged' => 'false']]],
            'invalid first seen type' => [['details' => ['firstSeen' => 123]]],
            'invalid first seen day' => [['details' => ['firstSeen' => '2026-02-29T10:30:00']]],
            'invalid first seen time' => [['details' => ['firstSeen' => '2026-08-15T24:00:00']]],
            'invalid first seen format' => [['details' => ['firstSeen' => '15/08/2026']]],
            'invalid isolation flag' => [['details' => ['isIsolated' => 1]]],
        ];
    }

    public function test_duplicates_between_pages_for_the_same_parent_are_rejected(): void
    {
        Http::fakeSequence()->push($this->page([]))
            ->push($this->page([$this->item()], 1, true))->push($this->page([$this->item()], 2));

        $this->assertStringContainsString('duplicati', $this->assertSanitizedFailure()->getMessage());
        Http::assertSentCount(3);
    }

    public function test_a_later_page_rpc_error_discards_the_partial_result(): void
    {
        Http::fakeSequence()->push($this->page([]))->push($this->page([$this->item()], 1, true))
            ->push(['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => self::TEST_KEY.' private-data']]);

        $this->assertSanitizedFailure();
        Http::assertSentCount(3);
    }

    public function test_the_safety_page_limit_fails_instead_of_returning_a_truncated_list(): void
    {
        Http::fake(fn (Request $request) => Http::response(isset($request['params']['filters']['details'])
            ? $this->page([])
            : $this->page([$this->item(['id' => 'endpoint-'.$request['params']['page']])], $request['params']['page'], true)));

        $this->assertStringContainsString('1000 pagine', $this->assertSanitizedFailure()->getMessage());
        Http::assertSentCount(1001);
    }

    public function test_rate_limiting_stops_the_read_without_retrying(): void
    {
        Http::fake(['*' => Http::response(self::TEST_KEY.' private-data', 429, ['Retry-After' => '90'])]);

        $exception = $this->assertSanitizedFailure();
        $this->assertTrue($exception->stopSynchronization);
        $this->assertStringContainsString('tra 90 secondi', $exception->getMessage());
        Http::assertSentCount(1);
    }

    public function test_a_network_failure_stops_the_read_without_retrying_or_exposing_the_cause(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException(self::TEST_KEY.' private-data');
        });

        $this->assertTrue($this->assertSanitizedFailure()->stopSynchronization);
        $this->assertSame(1, $attempts);
    }

    public function test_invalid_company_ids_do_not_send_requests(): void
    {
        foreach (['', ' ', str_repeat('a', 256)] as $companyId) {
            try {
                $this->readInventory($companyId);
                $this->fail('Un ID azienda non valido deve essere rifiutato.');
            } catch (GravityZoneException $exception) {
                $this->assertSame('ID azienda GravityZone non valido.', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_endpoints_in_the_company_deleted_folder_or_below_it_are_not_returned(): void
    {
        // Verified live on 11/11/2026: each company has a type-4 "Deleted" group directly below it.
        $this->fakeInventory([
            $this->item(['id' => 'deleted-pc', 'parentId' => 'deleted-folder']),
            $this->item(['id' => 'nested-deleted-pc', 'parentId' => 'old-office']),
            $this->item(['id' => 'active-pc', 'parentId' => 'group-1']),
            $this->item(['id' => 'custom-deleted-pc', 'parentId' => 'custom-deleted']),
            $this->item(['id' => 'group-1', 'name' => 'Ufficio', 'type' => 4, 'parentId' => 'company-1']),
            $this->item(['id' => 'deleted-folder', 'name' => 'Deleted', 'type' => 4, 'parentId' => 'company-1']),
            $this->item(['id' => 'old-office', 'name' => 'Vecchio ufficio', 'type' => 4, 'parentId' => 'deleted-folder']),
            $this->item(['id' => 'custom-deleted', 'name' => 'Deleted', 'type' => 4, 'parentId' => 'group-1']),
        ]);

        $this->assertSame(['active-pc', 'custom-deleted-pc'], array_column($this->readInventory(), 'id'));
        Http::assertSent(fn (Request $request): bool => $request['params'] === $this->endpointParameters('company-1', 1));
    }

    private function fakeInventory(array $items): void
    {
        Http::fakeSequence()->push($this->page([]))->push($this->page($items));
    }

    public function test_companies_are_read_four_per_round_in_page_order_and_a_failure_stays_with_its_company(): void
    {
        $this->freezeTime();
        Http::fake(function (Request $request) {
            $company = $request['params']['parentId'];
            if (isset($request['params']['filters']['details'])) {
                return Http::response($this->page([]));
            }

            return Http::response($company === 'company-3'
                ? $this->page([$this->item(['id' => 'pc-3', 'companyId' => 'company-3'])], page: 2)
                : $this->page([$this->item(['id' => 'pc-'.substr($company, -1), 'companyId' => $company])]));
        });
        $ids = ['company-1', 'company-2', 'company-3', 'company-4', 'company-5'];

        $results = app(GravityZoneClient::class)->getNetworkInventoryItems(new GravityZoneSetting([
            'api_url' => self::API_URL, 'api_key' => self::TEST_KEY,
        ]), array_fill_keys($ids, []));

        $this->assertSame($ids, array_keys($results));
        $this->assertInstanceOf(GravityZoneException::class, $results['company-3']);
        $this->assertFalse($results['company-3']->stopSynchronization);
        foreach (['company-1', 'company-2', 'company-4', 'company-5'] as $company) {
            $this->assertSame(['pc-'.substr($company, -1)], array_column($results[$company], 'id'));
        }
        // Rounds: AD discovery of companies 1-4, their computers, then company 5 twice.
        $sent = Http::recorded()->map(fn (array $pair): string => $pair[0]['params']['parentId'].(isset($pair[0]['params']['filters']['details']) ? ':ad' : ''))->all();
        $this->assertSame(['company-1:ad', 'company-2:ad', 'company-3:ad', 'company-4:ad', 'company-1', 'company-2', 'company-3', 'company-4', 'company-5:ad', 'company-5'], $sent);
        Sleep::assertSequence(array_fill(0, 3, Sleep::for(1000)->milliseconds()));
    }

    private function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/GravityZone/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function endpointParameters(string $parentId, int $page): array
    {
        return ['parentId' => $parentId, 'page' => $page, 'perPage' => 100, 'filters' => [
            'type' => ['computers' => true, 'virtualMachines' => true, 'ec2Instances' => true, 'groups' => true],
            'depth' => ['allItemsRecursively' => true],
        ]];
    }

    private function item(array $changes = []): array
    {
        return array_replace(['id' => 'endpoint-1', 'name' => 'Postazione di prova', 'type' => 5,
            'parentId' => 'group-1', 'companyId' => 'company-1'], $changes);
    }

    private function page(array $items, int $page = 1, bool $hasMore = false): array
    {
        return ['id' => self::RPC_ID, 'jsonrpc' => '2.0', 'result' => [
            'page' => $page, 'perPage' => 100, 'hasMoreRecords' => $hasMore, 'items' => $items,
        ]];
    }

    private function readInventory(string $companyId = 'company-1', array $otherCompanyIds = []): array
    {
        $result = app(GravityZoneClient::class)->getNetworkInventoryItems(new GravityZoneSetting([
            'api_url' => self::API_URL, 'api_key' => self::TEST_KEY,
        ]), [$companyId => $otherCompanyIds])[$companyId];

        return $result instanceof GravityZoneException ? throw $result : $result;
    }

    private function assertSanitizedFailure(): GravityZoneException
    {
        try {
            $this->readInventory();
            $this->fail('La risposta inventario non valida deve essere rifiutata.');
        } catch (GravityZoneException $exception) {
            $this->assertStringNotContainsString(self::TEST_KEY, $exception->getMessage());
            $this->assertStringNotContainsString('private-data', $exception->getMessage());
            $this->assertStringNotContainsString(self::API_URL, $exception->getMessage());
            $this->assertNull($exception->getPrevious());

            return $exception;
        }
    }
}
