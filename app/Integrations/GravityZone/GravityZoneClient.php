<?php

namespace App\Integrations\GravityZone;

use App\Models\GravityZoneSetting;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use GuzzleHttp\Utils;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use JsonException;
use stdClass;

class GravityZoneClient
{
    public const READ_ONLY_METHODS = ['getCompaniesList', 'getCompanyDetails', 'getLicenseInfo', 'getNetworkInventoryItems', 'getManagedEndpointDetails'];

    private const REQUEST_ID = '103d7b05-ec02-481b-9ed6-c07b97de2b7a';

    private const RETRY_AT_CACHE_KEY = 'gravityzone-retry-at';

    private mixed $handler = null;

    /** @var array<string, float> */
    private array $nextRequestAt = [];

    /** @return list<array{id: string, name: string}> */
    public function getCompaniesList(GravityZoneSetting $settings, ?string $parentId = null): array
    {
        if ($parentId !== null && (trim($parentId) === '' || mb_strlen($parentId) > 255)) {
            throw new GravityZoneException('ID azienda GravityZone non valido.');
        }

        $payload = $this->call($settings, '/v1.0/jsonrpc/network', 'getCompaniesList', $parentId === null ? [] : ['parentId' => $parentId]);

        if (! isset($payload->result) || ! is_array($payload->result) || ! array_is_list($payload->result)) {
            throw new GravityZoneException('La risposta GravityZone non contiene un elenco aziende valido.');
        }

        $companies = [];

        foreach ($payload->result as $company) {
            if (
                ! $company instanceof stdClass ||
                ! is_string($company->id ?? null) || trim($company->id) === '' || mb_strlen($company->id) > 255 ||
                in_array($company->id, array_column($companies, 'id'), true) ||
                ! is_string($company->name ?? null) || trim($company->name) === ''
            ) {
                throw new GravityZoneException('La risposta GravityZone contiene dati azienda incompleti o non validi.');
            }

            $companies[] = ['id' => $company->id, 'name' => $company->name];
        }

        return $companies;
    }

    /**
     * Default limit of ten requests/second per key: eight concurrent requests per second.
     *
     * @param  list<string>  $companyIds
     * @return array<string, array{is_suspended: ?bool, gravityzone_type: ?int, can_manage_security: ?bool}|GravityZoneException>
     */
    public function getCompanyDetails(GravityZoneSetting $settings, array $companyIds): array
    {
        $this->assertIds($companyIds, 'ID azienda GravityZone non valido.');

        return $this->concurrently($settings, '/v1.0/jsonrpc/companies', 'getCompanyDetails', array_combine($companyIds, array_map(
            fn (string $id): Generator => $this->single(['companyId' => $id], fn (mixed $company): array => $this->companyDetails($company, $id)),
            $companyIds,
        )), 8);
    }

    /**
     * Licensing allows five requests/second per key: four concurrent requests per second.
     *
     * @param  list<string>  $companyIds
     * @return array<string, array{technical_license_type: string, technical_expires_on: ?string, technical_product: ?string}|GravityZoneException>
     */
    public function getLicenseInfo(GravityZoneSetting $settings, array $companyIds): array
    {
        $this->assertIds($companyIds, 'ID azienda GravityZone non valido.');

        return $this->concurrently($settings, '/v1.0/jsonrpc/licensing', 'getLicenseInfo', array_combine($companyIds, array_map(
            fn (string $id): Generator => $this->single(['companyId' => $id], fn (mixed $license): array => $this->licenseInfo($license)),
            $companyIds,
        )), 4);
    }

    /**
     * Reads the computers of several companies: each company's pages stay in order, four requests per second overall.
     *
     * @param  array<string, list<string>>  $companies  Company ID => other imported company IDs its recursive inventory may contain.
     * @return array<string, list<array{id: string, name: string, platform: ?string, is_managed: ?bool, is_isolated: ?bool, first_seen_on: ?string}>|GravityZoneException>
     */
    public function getNetworkInventoryItems(GravityZoneSetting $settings, array $companies): array
    {
        $ids = array_map('strval', array_keys($companies));
        $this->assertIds($ids, 'ID azienda GravityZone non valido.');

        return $this->concurrently($settings, '/v1.1/jsonrpc/network', 'getNetworkInventoryItems', array_combine($ids, array_map(
            fn (string $id): Generator => $this->inventoryReader($id, $companies[$id]),
            $ids,
        )), 4);
    }

    /**
     * Reads the license code of each managed endpoint, eight concurrent requests per second.
     *
     * @param  list<string>  $endpointIds
     * @return array<string, int|GravityZoneException|null>
     */
    public function getManagedEndpointDetails(GravityZoneSetting $settings, array $endpointIds, string $companyId): array
    {
        $this->assertIds([...$endpointIds, $companyId], 'ID endpoint o azienda GravityZone non valido.');

        return $this->concurrently($settings, '/v1.0/jsonrpc/network', 'getManagedEndpointDetails', array_combine($endpointIds, array_map(
            fn (string $id): Generator => $this->single(['endpointId' => $id], fn (mixed $endpoint): ?int => $this->endpointLicense($endpoint, $id, $companyId)),
            $endpointIds,
        )), 8);
    }

    /** @return array{is_suspended: ?bool, gravityzone_type: ?int, can_manage_security: ?bool} */
    private function companyDetails(mixed $company, string $companyId): array
    {
        if (! $company instanceof stdClass || ($company->id ?? null) !== $companyId ||
            (isset($company->isSuspended) && ! is_bool($company->isSuspended)) ||
            (isset($company->type) && ! in_array($company->type, [0, 1], true)) ||
            (isset($company->canBeManagedByAbove) && ! is_bool($company->canBeManagedByAbove))) {
            throw new GravityZoneException('La risposta GravityZone non contiene uno stato aziendale valido.');
        }

        return [
            'is_suspended' => $company->isSuspended ?? null,
            'gravityzone_type' => $company->type ?? null,
            'can_manage_security' => $company->canBeManagedByAbove ?? null,
        ];
    }

    /** @return array{technical_license_type: string, technical_expires_on: ?string, technical_product: ?string} */
    private function licenseInfo(mixed $license): array
    {
        if (! $license instanceof stdClass || ! is_int($license->subscriptionType ?? null)) {
            throw new GravityZoneException('La risposta GravityZone non contiene dati di licenza aziendale validi.');
        }

        $productType = $license->assignedProductType ?? null;
        $protectionModel = $license->assignedProtectionModel ?? null;

        if (($productType !== null && ! is_int($productType)) ||
            ($protectionModel !== null && ! is_string($protectionModel))) {
            throw new GravityZoneException('La risposta GravityZone contiene un prodotto aziendale non valido.');
        }

        $product = match ($productType) {
            0 => 'Endpoint Security',
            3 => 'Bitdefender EDR',
            5 => 'Bitdefender PHASR',
            default => null,
        };

        if ($productType === 0 && $license->subscriptionType === 3) {
            $model = match ($protectionModel) {
                'aLaCarte' => 'À la carte',
                'mspSecureEssentials' => 'MSP Secure Essentials',
                'mspSecure' => 'MSP Secure',
                'mspSecurePlus' => 'MSP Secure Plus',
                'mspSecureExtra' => 'MSP Secure Extra',
                default => null,
            };
            if ($model !== null) {
                $product .= ' · '.$model;
            }
        }

        // Monthly expiryDate is distinct from the company's subscription end.
        $monthly = $license->subscriptionType === 3;
        $expiry = $monthly ? ($license->endSubscription ?? null) : ($license->expiryDate ?? null);

        if ($expiry !== null) {
            $format = $monthly ? 'Y-m-d' : 'Y-m-d\TH:i:s';
            $pattern = $monthly ? '/^\d{4}-\d{2}-\d{2}$/D' : '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D';
            $date = is_string($expiry) && preg_match($pattern, $expiry)
                ? DateTimeImmutable::createFromFormat('!'.$format, $expiry, new DateTimeZone('UTC')) : false;

            if (! $date || DateTimeImmutable::getLastErrors() !== false || $date->format($format) !== $expiry) {
                throw new GravityZoneException('La risposta GravityZone contiene una scadenza tecnica non valida.');
            }

            // The API omits the timezone: preserve its calendar date without conversion.
            $expiry = $date->format('Y-m-d');
        }

        return [
            'technical_license_type' => match ($license->subscriptionType) {
                1 => 'Prova',
                2 => 'Licenza',
                3 => 'Mensile',
                4 => 'Licenza mensile di prova',
                5 => 'Abbonamento mensile di prova',
                6 => 'FRAT',
                default => 'Codice '.$license->subscriptionType,
            },
            'technical_expires_on' => $expiry,
            'technical_product' => $product,
        ];
    }

    /** Yields one company's inventory page requests in order and returns its endpoints. */
    private function inventoryReader(string $companyId, array $otherCompanyIds): Generator
    {
        $parents = [$companyId];

        // AD computers require an explicit read below the discovered AD folder.
        foreach (yield from $this->inventoryPages($companyId, $companyId, ['details' => ['name' => 'Active Directory']], $otherCompanyIds) as $item) {
            if ($item->type === 4 && $item->name === 'Active Directory' && $item->id !== $companyId) {
                $parents[] = $item->id;
            }
        }

        $endpoints = [];

        foreach ($parents as $parentId) {
            foreach (yield from $this->inventoryPages($parentId, $companyId, [
                'type' => ['computers' => true, 'virtualMachines' => true, 'ec2Instances' => true],
                'depth' => ['allItemsRecursively' => true],
            ], $otherCompanyIds) as $item) {
                if (! in_array($item->type, [5, 6, 7], true)) {
                    continue;
                }

                $details = $item->details ?? null;

                if ($details !== null && ! $details instanceof stdClass) {
                    throw new GravityZoneException('La risposta GravityZone contiene dettagli endpoint non validi.');
                }

                $platform = $details->operatingSystemVersion ?? null;
                $managed = $details->isManaged ?? null;
                $isolated = $details->isIsolated ?? null;
                $firstSeen = $details->firstSeen ?? null;
                if ($firstSeen === '') {
                    $firstSeen = null;
                }

                if (($platform !== null && ! is_string($platform)) ||
                    ($managed !== null && ! is_bool($managed)) ||
                    ($isolated !== null && ! is_bool($isolated))) {
                    throw new GravityZoneException('La risposta GravityZone contiene dettagli endpoint non validi.');
                }

                if ($firstSeen !== null) {
                    $format = 'Y-m-d\TH:i:s';
                    $date = is_string($firstSeen) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D', $firstSeen)
                        ? DateTimeImmutable::createFromFormat('!'.$format, $firstSeen, new DateTimeZone('UTC')) : false;
                    if (! $date || DateTimeImmutable::getLastErrors() !== false || $date->format($format) !== $firstSeen) {
                        throw new GravityZoneException('La risposta GravityZone contiene una data di primo rilevamento non valida.');
                    }
                    // The documented timestamp has no timezone: preserve its calendar date.
                    $firstSeen = $date->format('Y-m-d');
                }

                // Company and AD queries may deliberately return the same endpoint.
                $endpoints[$item->id] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'platform' => is_string($platform) && trim($platform) !== '' ? $platform : null,
                    'is_managed' => $managed,
                    'is_isolated' => $isolated,
                    'first_seen_on' => $firstSeen,
                ];
            }
        }

        return array_values($endpoints);
    }

    private function endpointLicense(mixed $endpoint, string $endpointId, string $companyId): ?int
    {
        if (! $endpoint instanceof stdClass || ($endpoint->id ?? null) !== $endpointId ||
            ($endpoint->companyId ?? null) !== $companyId ||
            (isset($endpoint->agent) && ! $endpoint->agent instanceof stdClass)) {
            throw new GravityZoneException('La risposta GravityZone non contiene dettagli validi per l’endpoint e l’azienda richiesti.');
        }

        $licensed = $endpoint->agent->licensed ?? null;

        if ($licensed !== null && ! is_int($licensed)) {
            throw new GravityZoneException('La risposta GravityZone contiene uno stato licenza endpoint non valido.');
        }

        return in_array($licensed, [0, 1, 2, 6], true) ? $licensed : null;
    }

    /**
     * Yields each page request of one container, receives its result and returns the validated items.
     *
     * @return Generator<int, array, mixed, list<stdClass>>
     */
    private function inventoryPages(string $parentId, string $companyId, array $filters, array $otherCompanyIds): Generator
    {
        $seen = [];
        $items = [];

        for ($page = 1; $page <= 1000; $page++) {
            $result = yield ['parentId' => $parentId, 'page' => $page, 'perPage' => 100, 'filters' => $filters];

            if (! $result instanceof stdClass || ($result->page ?? null) !== $page ||
                ($result->perPage ?? null) !== 100 || ! is_bool($result->hasMoreRecords ?? null) ||
                ! is_array($result->items ?? null) || ! array_is_list($result->items) ||
                count($result->items) > 100 || ($result->hasMoreRecords && $result->items === [])) {
                throw new GravityZoneException('La risposta GravityZone contiene una pagina inventario non valida.');
            }

            foreach ($result->items as $item) {
                if (! $item instanceof stdClass ||
                    ! is_string($item->id ?? null) || trim($item->id) === '' || mb_strlen($item->id) > 255 ||
                    isset($seen[$item->id]) || ! is_string($item->name ?? null) || trim($item->name) === '' ||
                    ! is_string($item->companyId ?? null) || trim($item->companyId) === '' ||
                    ! is_int($item->type ?? null)) {
                    throw new GravityZoneException('La risposta GravityZone contiene elementi inventario incompleti, duplicati o di un’altra azienda.');
                }

                $seen[$item->id] = true;

                if ($item->companyId !== $companyId) {
                    // A partner's recursive inventory also contains separately imported clients.
                    if (in_array($item->companyId, $otherCompanyIds, true)) {
                        continue;
                    }

                    throw new GravityZoneException('La risposta GravityZone contiene elementi inventario incompleti, duplicati o di un’altra azienda.');
                }

                $items[] = $item;
            }

            if (! $result->hasMoreRecords) {
                return $items;
            }
        }

        throw new GravityZoneException('La lettura GravityZone supera il limite di sicurezza di 1000 pagine per contenitore. Nessun elenco parziale viene importato.');
    }

    /**
     * Drives readers concurrently. A reader is a generator that yields request params, receives the JSON-RPC
     * result and returns its value. Each round sends at most $perSecond requests, one per reader, and starts
     * at least one second after the previous round of the same method.
     *
     * @param  array<string, Generator>  $readers
     * @return array<string, mixed> In input order: each return value, or the failure; a stopping failure is returned for every reader not completed.
     */
    private function concurrently(GravityZoneSetting $settings, string $path, string $method, array $readers, int $perSecond): array
    {
        $results = array_fill_keys(array_keys($readers), null);
        $stop = null;

        while ($readers !== []) {
            $round = array_slice($readers, 0, $perSecond, true);
            $promises = [];

            try {
                if ($stop === null) {
                    $this->pace($method, 1000);
                    foreach ($round as $key => $reader) {
                        $promises[$key] = $this->connection($settings)->async()->post($path, $this->body($method, $reader->current()))->buildPromise();
                    }
                }
            } catch (GravityZoneException $exception) {
                $stop = $exception;
            }

            foreach ($round as $key => $reader) {
                try {
                    $response = isset($promises[$key]) ? $promises[$key]->wait() : throw $stop;
                    // Asynchronous requests return connection failures instead of throwing them.
                    if (! $response instanceof Response) {
                        throw $this->networkError();
                    }

                    $reader->send($this->responsePayload($response)->result ?? null);
                    if ($reader->valid()) {
                        continue;
                    }

                    $results[$key] = $reader->getReturn();
                } catch (GravityZoneException $exception) {
                    $results[$key] = $exception;
                    if ($exception->stopSynchronization) {
                        $stop ??= $exception;
                    }
                }

                unset($readers[$key]);
            }
        }

        return $results;
    }

    private function single(array $params, callable $parse): Generator
    {
        return $parse(yield $params);
    }

    private function assertIds(array $ids, string $message): void
    {
        foreach ($ids as $id) {
            if (! is_string($id) || trim($id) === '' || mb_strlen($id) > 255) {
                throw new GravityZoneException($message);
            }
        }
    }

    private function call(GravityZoneSetting $settings, string $path, string $method, array $params): stdClass
    {
        $connection = $this->connection($settings);
        // Sequential reads stay at four per second, below the lowest documented limit of five.
        $this->pace('sequential', 250);

        try {
            $response = $connection->post($path, $this->body($method, $params));
        } catch (ConnectionException) {
            throw $this->networkError();
        }

        return $this->responsePayload($response);
    }

    private function body(string $method, array $params): array
    {
        return ['jsonrpc' => '2.0', 'id' => self::REQUEST_ID, 'method' => $method, 'params' => $params];
    }

    /** Waits only for the part of the interval not already spent waiting for the previous response. */
    private function pace(string $key, int $milliseconds): void
    {
        $wait = ($this->nextRequestAt[$key] ?? 0) - now()->getPreciseTimestamp(3);

        if ($wait > 0) {
            Sleep::for((int) ceil($wait))->milliseconds();
        }

        $this->nextRequestAt[$key] = now()->getPreciseTimestamp(3) + $milliseconds;
    }

    private function networkError(): GravityZoneException
    {
        return new GravityZoneException('Impossibile contattare GravityZone: errore di rete o tempo di attesa scaduto. Riprova più tardi.', stopSynchronization: true);
    }

    private function connection(GravityZoneSetting $settings): PendingRequest
    {
        $seconds = max(0, Cache::get(self::RETRY_AT_CACHE_KEY, 0) - now()->getTimestamp());

        if ($seconds > 0) {
            throw new GravityZoneException("Limite richieste GravityZone raggiunto. Riprova tra {$seconds} secondi.", stopSynchronization: true);
        }

        try {
            $key = $settings->api_key;
        } catch (DecryptException) {
            throw new GravityZoneException('La chiave GravityZone salvata non è leggibile. Inseriscila nuovamente nelle impostazioni.', stopSynchronization: true);
        }

        $url = rtrim($settings->api_url ?? '', '/');
        $parts = parse_url($url);

        if (
            ! is_string($key) || trim($key) === '' ||
            ! filter_var($url, FILTER_VALIDATE_URL) ||
            ($parts['scheme'] ?? '') !== 'https' ||
            ! str_ends_with($parts['path'] ?? '', '/api') ||
            isset($parts['user']) || isset($parts['pass']) ||
            isset($parts['query']) || isset($parts['fragment'])
        ) {
            throw new GravityZoneException('Configura un URL API HTTPS valido e una chiave GravityZone prima di leggere i dati.', stopSynchronization: true);
        }

        // One shared handler reuses the TLS connections across the reads of a synchronization.
        return Http::setHandler($this->handler ??= Utils::chooseHandler())->baseUrl($url)->acceptJson()->withBasicAuth($key, '')
            ->withoutRedirecting()->connectTimeout(5)->timeout(20);
    }

    private function responsePayload(Response $response): stdClass
    {
        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');
            $seconds = ctype_digit($retryAfter) ? (int) $retryAfter : null;

            if ($seconds === null && preg_match('/^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT$/D', $retryAfter)) {
                $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $retryAfter, new DateTimeZone('UTC'));
                $seconds = $date && DateTimeImmutable::getLastErrors() === false
                    ? max(0, $date->getTimestamp() - now()->getTimestamp())
                    : null;
            }

            // Without usable Retry-After, wait one minute before allowing a manual retry.
            $seconds ??= 60;
            if ($seconds > 0) {
                Cache::put(self::RETRY_AT_CACHE_KEY, now()->getTimestamp() + $seconds, $seconds);
            }

            throw new GravityZoneException("Limite richieste GravityZone raggiunto. Riprova tra {$seconds} secondi.", stopSynchronization: true);
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new GravityZoneException('Accesso GravityZone negato. Verifica la chiave API e le sue autorizzazioni.', stopSynchronization: true);
        }

        if (! $response->successful()) {
            throw new GravityZoneException('GravityZone ha restituito un errore HTTP. Verifica l’URL API o riprova più tardi.', stopSynchronization: true);
        }

        try {
            $payload = json_decode($response->body(), flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new GravityZoneException('La risposta GravityZone non contiene JSON valido. Verifica l’URL API.');
        }

        if (! $payload instanceof stdClass || ($payload->jsonrpc ?? null) !== '2.0' || ($payload->id ?? null) !== self::REQUEST_ID) {
            throw new GravityZoneException('La risposta GravityZone non corrisponde alla richiesta JSON-RPC.');
        }

        if (property_exists($payload, 'error')) {
            throw new GravityZoneException('GravityZone ha rifiutato la richiesta API. Verifica la chiave e le autorizzazioni per la lettura richiesta.');
        }

        return $payload;
    }
}
