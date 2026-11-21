<?php

declare(strict_types=1);

namespace Hosterix\Providers\Services;

use Hosterix\Providers\Contracts\OpenproviderClientInterface;
use Hosterix\Providers\Exceptions\ProviderAuthenticationException;
use Hosterix\Providers\Exceptions\ProviderRequestException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;

/**
 * Client reale per l'API REST di OpenProvider (v1beta).
 *
 * Il login avviene alla prima richiesta, non alla costruzione: risolvere il servizio
 * dal container non apre connessioni. Il token ottenuto vale per tutte le richieste
 * successive della stessa istanza.
 */
class OpenproviderService implements OpenproviderClientInterface
{
    private string $token = '';

    private readonly PendingRequest $http;

    public function __construct(Http $http)
    {
        $base = rtrim((string) config('openprovider.api_url'), '/');

        $this->http = $http->baseUrl($base)->acceptJson()->asJson();
    }

    private function login(): void
    {
        $response = $this->http->post('/v1beta/auth/login', [
            'username' => config('openprovider.username'),
            'password' => config('openprovider.password'),
        ]);

        $body = $response->json();

        if (($body['code'] ?? -1) !== 0) {
            throw new ProviderAuthenticationException('OpenProvider login failed: '.($body['desc'] ?? 'unknown error'));
        }

        $this->token = (string) ($body['data']['token'] ?? '');
    }

    private function authed(): PendingRequest
    {
        if ($this->token === '') {
            $this->login();
        }

        return $this->http->withToken($this->token);
    }

    // -- Domain availability --------------------------------------------------

    public function checkAvailability(string $domain): array
    {
        [$name, $extension] = $this->splitDomain($domain);

        $body = $this->request('post', '/v1beta/domains/check', [
            'domains' => [['name' => $name, 'extension' => $extension]],
        ]);

        $result = $body['data']['results'][0] ?? null;
        $status = $result['status'] ?? 'unknown';

        return [
            'domain' => $domain,
            'available' => $status === 'free',
            'status' => $status,
        ];
    }

    public function checkAvailabilityBulk(array $domains): array
    {
        if ($domains === []) {
            return [];
        }

        $payload = array_map(function (string $domain): array {
            [$name, $extension] = $this->splitDomain($domain);

            return ['name' => $name, 'extension' => $extension];
        }, $domains);

        $body = $this->request('post', '/v1beta/domains/check', [
            'domains' => $payload,
        ]);

        return array_map(fn (array $result): array => [
            'domain' => $result['domain'] ?? '',
            'available' => ($result['status'] ?? 'unknown') === 'free',
            'status' => $result['status'] ?? 'unknown',
        ], $body['data']['results'] ?? []);
    }

    // -- Domain listing -------------------------------------------------------

    public function getDomains(int $offset = 0, int $limit = 100): array
    {
        $body = $this->request('get', '/v1beta/domains', [
            'offset' => $offset,
            'limit' => $limit,
        ]);

        return array_map(fn (array $item): array => [
            'op_id' => (int) ($item['id'] ?? 0),
            'name' => ($item['domain']['name'] ?? '').'.'.($item['domain']['extension'] ?? ''),
            'status' => $item['status'] ?? '',
            'expires_at' => $item['expiration_date'] ?? null,
        ], $body['data']['results'] ?? []);
    }

    // -- Registration ---------------------------------------------------------

    public function registerDomain(array $data): array
    {
        [$name, $extension] = $this->splitDomain($data['domain']);

        $nameservers = array_map(fn (string $ns): array => ['name' => $ns], $data['nameservers']);

        $body = $this->request('post', '/v1beta/domains', [
            'domain' => ['name' => $name, 'extension' => $extension],
            'period' => $data['years'] ?? 1,
            'name_servers' => $nameservers,
            'owner_handle' => $data['registrant']['handle'] ?? null,
            'autorenew' => 'default',
        ]);

        return [
            'op_id' => (int) ($body['data']['id'] ?? 0),
            'status' => 'pending',
        ];
    }

    // -- Renewal --------------------------------------------------------------

    public function renewDomain(int $opId, int $years): array
    {
        $body = $this->request('post', "/v1beta/domains/{$opId}/renew", [
            'period' => $years,
        ]);

        return [
            'op_id' => $opId,
            'status' => $body['data']['status'] ?? 'pending',
        ];
    }

    // -- DNS ------------------------------------------------------------------

    public function getDnsRecords(string $domainName): array
    {
        $response = $this->authed()->get("/v1beta/dns/zones/{$domainName}/records", [
            'limit' => 500,
        ]);

        $body = $response->json();

        if (($body['code'] ?? -1) !== 0) {
            return [];
        }

        return array_map(function (array $r): array {
            $out = [
                'type' => $r['type'] ?? '',
                'name' => $r['name'] ?? '@',
                'value' => $r['value'] ?? '',
                'ttl' => (int) ($r['ttl'] ?? 3600),
            ];

            if (isset($r['prio'])) {
                $out['prio'] = (int) $r['prio'];
            }

            return $out;
        }, $body['data']['results'] ?? []);
    }

    public function createDnsRecord(string $domainName, array $record): array
    {
        $payload = $this->serializeRecord($record);

        $this->request('put', "/v1beta/dns/zones/{$domainName}", [
            'records' => [
                'add' => [$payload],
            ],
        ]);

        return $this->normalizeRecord($payload);
    }

    public function updateDnsRecord(string $domainName, array $oldRecord, array $newRecord): array
    {
        $remove = $this->serializeRecord($oldRecord);
        $add = $this->serializeRecord($newRecord);

        $this->request('put', "/v1beta/dns/zones/{$domainName}", [
            'records' => [
                'remove' => [$remove],
                'add' => [$add],
            ],
        ]);

        return $this->normalizeRecord($add);
    }

    public function deleteDnsRecord(string $domainName, array $record): bool
    {
        $payload = $this->serializeRecord($record);

        try {
            $this->request('put', "/v1beta/dns/zones/{$domainName}", [
                'records' => [
                    'remove' => [$payload],
                ],
            ]);
        } catch (\Throwable $e) {
            // Idempotenza: se il record non esisteva, OP risponde con errore
            // ma noi consideriamo la cancellazione riuscita.
            if (str_contains($e->getMessage(), 'not found')) {
                return true;
            }
            throw $e;
        }

        return true;
    }

    /**
     * @param  array{type?: string, name?: string, value?: string, ttl?: int, prio?: int}  $r
     * @return array{type: string, name: string, value: string, ttl: int, prio?: int}
     */
    private function serializeRecord(array $r): array
    {
        $out = [
            'type' => strtoupper((string) ($r['type'] ?? 'A')),
            'name' => (string) ($r['name'] ?? '@'),
            'value' => (string) ($r['value'] ?? ''),
            'ttl' => (int) ($r['ttl'] ?? 3600),
        ];

        if (isset($r['prio'])) {
            $out['prio'] = (int) $r['prio'];
        }

        return $out;
    }

    /**
     * @param  array{type: string, name: string, value: string, ttl: int, prio?: int}  $r
     * @return array{type: string, name: string, value: string, ttl: int, prio?: int}
     */
    private function normalizeRecord(array $r): array
    {
        return $r;
    }

    // -- Transfer -------------------------------------------------------------

    public function transferDomain(array $data): array
    {
        [$name, $extension] = $this->splitDomain($data['domain']);

        $nameservers = array_map(fn (string $ns): array => ['name' => $ns], $data['nameservers']);

        $body = $this->request('post', '/v1beta/domains/transfer', [
            'domain' => ['name' => $name, 'extension' => $extension],
            'period' => $data['years'] ?? 1,
            'auth_code' => $data['auth_code'],
            'name_servers' => $nameservers,
            'owner_handle' => $data['registrant']['handle'] ?? null,
            'autorenew' => 'default',
        ]);

        return [
            'op_id' => (int) ($body['data']['id'] ?? 0),
            'status' => 'pending_transfer',
        ];
    }

    public function updateNameservers(int $opId, array $nameservers): array
    {
        $nsPayload = array_map(fn (string $ns): array => ['name' => $ns], $nameservers);

        $this->request('put', "/v1beta/domains/{$opId}", [
            'name_servers' => $nsPayload,
        ]);

        return [
            'op_id' => $opId,
            'status' => 'updated',
        ];
    }

    public function getDomainStatus(int $opId): array
    {
        $body = $this->request('get', "/v1beta/domains/{$opId}");

        return [
            'op_id' => $opId,
            'status' => $body['data']['status'] ?? 'unknown',
        ];
    }

    public function cancelTransfer(int $opId): array
    {
        // OP API: cancellazione transfer via DELETE su /v1beta/domains/transfers/{id}
        // (potrebbe variare per il piano account; consultare docs OP). In caso di
        // 404 (transfer già chiuso) ritorniamo 'cancelled' come idempotente.
        try {
            $this->request('delete', "/v1beta/domains/transfers/{$opId}");
        } catch (ProviderRequestException $e) {
            if ($e->httpStatus === 404) {
                return ['op_id' => $opId, 'status' => 'cancelled'];
            }
            throw $e;
        }

        return ['op_id' => $opId, 'status' => 'cancelled'];
    }

    // -- Helpers --------------------------------------------------------------

    /**
     * Esegue una richiesta autenticata e valida la risposta.
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $uri, array $data = []): array
    {
        $response = match ($method) {
            'get' => $this->authed()->get($uri, $data),
            'post' => $this->authed()->post($uri, $data),
            'put' => $this->authed()->put($uri, $data),
            'delete' => $this->authed()->delete($uri, $data),
            default => throw new \InvalidArgumentException("Metodo HTTP non supportato: {$method}"),
        };

        $body = $response->json();

        if (($body['code'] ?? -1) !== 0) {
            throw new ProviderRequestException(
                "OpenProvider {$uri} error: ".($body['desc'] ?? 'unknown'),
                $response->status(),
            );
        }

        return $body;
    }

    /**
     * Separa "example.com" in ["example", "com"].
     *
     * @return array{0: string, 1: string}
     */
    private function splitDomain(string $domain): array
    {
        $parts = explode('.', $domain, 2);

        return [$parts[0], $parts[1] ?? ''];
    }
}
