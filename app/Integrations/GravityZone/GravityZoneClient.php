<?php

namespace App\Integrations\GravityZone;

use App\Models\GravityZoneSetting;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use stdClass;

class GravityZoneClient
{
    public const READ_ONLY_METHODS = ['getCompaniesList'];

    private const REQUEST_ID = '103d7b05-ec02-481b-9ed6-c07b97de2b7a';

    /** @return list<array{id: string, name: string}> */
    public function getCompaniesList(GravityZoneSetting $settings): array
    {
        try {
            $key = $settings->api_key;
        } catch (DecryptException) {
            throw new GravityZoneException('La chiave GravityZone salvata non è leggibile. Inseriscila nuovamente nelle impostazioni.');
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
            throw new GravityZoneException('Configura un URL API HTTPS valido e una chiave GravityZone prima di verificare la connessione.');
        }

        try {
            $response = Http::acceptJson()
                ->withBasicAuth($key, '')
                ->withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(20)
                ->post($url.'/v1.0/jsonrpc/network', [
                    'jsonrpc' => '2.0',
                    'id' => self::REQUEST_ID,
                    'method' => 'getCompaniesList',
                    'params' => [],
                ]);
        } catch (ConnectionException) {
            throw new GravityZoneException('Impossibile contattare GravityZone: errore di rete o tempo di attesa scaduto. Riprova più tardi.');
        }

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
                : "Limite richieste GravityZone raggiunto. Riprova tra {$seconds} secondi.");
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new GravityZoneException('Accesso GravityZone negato. Verifica la chiave API e le sue autorizzazioni.');
        }

        if (! $response->successful()) {
            throw new GravityZoneException('GravityZone ha restituito un errore HTTP. Verifica l’URL API o riprova più tardi.');
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
            throw new GravityZoneException('GravityZone ha rifiutato la richiesta API. Verifica la chiave e le autorizzazioni per la lettura delle aziende.');
        }

        if (! isset($payload->result) || ! is_array($payload->result) || ! array_is_list($payload->result)) {
            throw new GravityZoneException('La risposta GravityZone non contiene un elenco aziende valido.');
        }

        $companies = [];

        foreach ($payload->result as $company) {
            if (
                ! $company instanceof stdClass ||
                ! is_string($company->id ?? null) || trim($company->id) === '' ||
                ! is_string($company->name ?? null) || trim($company->name) === ''
            ) {
                throw new GravityZoneException('La risposta GravityZone contiene dati azienda incompleti o non validi.');
            }

            $companies[] = ['id' => $company->id, 'name' => $company->name];
        }

        return $companies;
    }
}
