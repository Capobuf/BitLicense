<?php

namespace App\Integrations\GravityZone;

use App\Models\GravityZoneSetting;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use JsonException;
use stdClass;

class GravityZoneClient
{
    public const READ_ONLY_METHODS = ['getCompaniesList', 'getLicenseInfo', 'getNetworkInventoryItems'];

    private const REQUEST_ID = '103d7b05-ec02-481b-9ed6-c07b97de2b7a';

    /** @return list<array{id: string, name: string}> */
    public function getCompaniesList(GravityZoneSetting $settings): array
    {
        try {
            $response = $this->connection($settings)->post('/v1.0/jsonrpc/network', [
                'jsonrpc' => '2.0',
                'id' => self::REQUEST_ID,
                'method' => 'getCompaniesList',
                'params' => [],
            ]);
        } catch (ConnectionException) {
            throw new GravityZoneException('Impossibile contattare GravityZone: errore di rete o tempo di attesa scaduto. Riprova più tardi.', stopSynchronization: true);
        }

        $payload = $this->responsePayload($response);

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

    /** @return array{technical_license_type: string, technical_expires_on: ?string} */
    public function getLicenseInfo(GravityZoneSetting $settings, string $companyId): array
    {
        if (trim($companyId) === '' || mb_strlen($companyId) > 255) {
            throw new GravityZoneException('ID azienda GravityZone non valido.');
        }

        try {
            $response = $this->connection($settings)->post('/v1.0/jsonrpc/licensing', [
                'jsonrpc' => '2.0',
                'id' => self::REQUEST_ID,
                'method' => 'getLicenseInfo',
                'params' => ['companyId' => $companyId],
            ]);
        } catch (ConnectionException) {
            throw new GravityZoneException('Impossibile contattare GravityZone: errore di rete o tempo di attesa scaduto. Riprova più tardi.', stopSynchronization: true);
        }

        $license = $this->responsePayload($response)->result ?? null;

        if (! $license instanceof stdClass || ! is_int($license->subscriptionType ?? null)) {
            throw new GravityZoneException('La risposta GravityZone non contiene dati di licenza aziendale validi.');
        }

        $expiry = $license->expiryDate ?? null;

        if ($expiry !== null) {
            $date = is_string($expiry) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D', $expiry)
                ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $expiry, new DateTimeZone('UTC')) : false;

            if (! $date || DateTimeImmutable::getLastErrors() !== false || $date->format('Y-m-d\TH:i:s') !== $expiry) {
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
        ];
    }

    /** @return list<array{id: string, name: string, platform: ?string, is_managed: ?bool, is_isolated: ?bool}> */
    public function getNetworkInventoryItems(GravityZoneSetting $settings, string $companyId): array
    {
        if (trim($companyId) === '' || mb_strlen($companyId) > 255) {
            throw new GravityZoneException('ID azienda GravityZone non valido.');
        }

        $parents = [$companyId];

        // AD computers require an explicit read below the discovered AD folder.
        foreach ($this->inventoryItems($settings, $companyId, $companyId, ['details' => ['name' => 'Active Directory']]) as $item) {
            if ($item->type === 4 && $item->name === 'Active Directory' && $item->id !== $companyId) {
                $parents[] = $item->id;
            }
        }

        $endpoints = [];

        foreach ($parents as $parentId) {
            foreach ($this->inventoryItems($settings, $parentId, $companyId, [
                'type' => ['computers' => true, 'virtualMachines' => true, 'ec2Instances' => true],
                'depth' => ['allItemsRecursively' => true],
            ]) as $item) {
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

                if (($platform !== null && ! is_string($platform)) ||
                    ($managed !== null && ! is_bool($managed)) ||
                    ($isolated !== null && ! is_bool($isolated))) {
                    throw new GravityZoneException('La risposta GravityZone contiene dettagli endpoint non validi.');
                }

                // Company and AD queries may deliberately return the same endpoint.
                $endpoints[$item->id] = [
                    'id' => $item->id,
                    'name' => $item->name,
                    'platform' => is_string($platform) && trim($platform) !== '' ? $platform : null,
                    'is_managed' => $managed,
                    'is_isolated' => $isolated,
                ];
            }
        }

        return array_values($endpoints);
    }

    /** @return iterable<stdClass> */
    private function inventoryItems(GravityZoneSetting $settings, string $parentId, string $companyId, array $filters): iterable
    {
        $seen = [];

        for ($page = 1; $page <= 1000; $page++) {
            try {
                $connection = $this->connection($settings);
                Sleep::for(250)->milliseconds();
                $response = $connection->post('/v1.1/jsonrpc/network', [
                    'jsonrpc' => '2.0',
                    'id' => self::REQUEST_ID,
                    'method' => 'getNetworkInventoryItems',
                    'params' => ['parentId' => $parentId, 'page' => $page, 'perPage' => 100, 'filters' => $filters],
                ]);
            } catch (ConnectionException) {
                throw new GravityZoneException('Impossibile contattare GravityZone: errore di rete o tempo di attesa scaduto. Riprova più tardi.', stopSynchronization: true);
            }

            $result = $this->responsePayload($response)->result ?? null;

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
                    ($item->companyId ?? null) !== $companyId || ! is_int($item->type ?? null)) {
                    throw new GravityZoneException('La risposta GravityZone contiene elementi inventario incompleti, duplicati o di un’altra azienda.');
                }

                $seen[$item->id] = true;
                yield $item;
            }

            if (! $result->hasMoreRecords) {
                return;
            }
        }

        throw new GravityZoneException('La lettura GravityZone supera il limite di sicurezza di 1000 pagine per contenitore. Nessun elenco parziale viene importato.');
    }

    private function connection(GravityZoneSetting $settings): PendingRequest
    {
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

        return Http::baseUrl($url)->acceptJson()->withBasicAuth($key, '')
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

            throw new GravityZoneException($seconds === null
                ? 'Limite richieste GravityZone raggiunto. Attendi prima di riprovare.'
                : "Limite richieste GravityZone raggiunto. Riprova tra {$seconds} secondi.", stopSynchronization: true);
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
