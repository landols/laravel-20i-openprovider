<?php

declare(strict_types=1);

namespace Hosterix\Providers\Services;

use Exception;
use Hosterix\Providers\Contracts\TwentiClientInterface;
use Hosterix\Providers\Exceptions\ProviderConfigurationException;
use Hosterix\Providers\Exceptions\ProviderRequestException;
use Hosterix\Providers\Exceptions\UnsupportedOperationException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

/**
 * Client reale per l'API REST 20i.
 *
 * Endpoint base: https://api.20i.com
 * Auth: Authorization: Bearer base64(api_key)
 */
class TwentiService implements TwentiClientInterface
{
    private readonly string $baseUrl;

    public function __construct(private readonly Http $http)
    {
        $this->baseUrl = 'https://api.20i.com';
    }

    // -- HTTP helpers ---------------------------------------------------------

    private function apiKey(): string
    {
        $key = (string) config('services.twenti.api_key');

        if ($key === '') {
            throw new ProviderConfigurationException('20i API key non configurata (TWENTI_API_KEY).');
        }

        return $key;
    }

    /**
     * Client per la Reseller API (endpoint /reseller/* e /package senza web/*).
     * Token: base64(API_KEY)
     */
    private function clientReseller(): PendingRequest
    {
        return $this->http
            ->withToken(base64_encode($this->apiKey()))
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->throw();
    }

    /**
     * Client per la Web Hosting API (endpoint /package/{id}/web/*, /email/*).
     * Token: base64(API_KEY+OAUTH_CLIENT_KEY) se oauth_client_key è configurata,
     * altrimenti fallback a solo API_KEY (backward compat).
     */
    private function clientHosting(): PendingRequest
    {
        $apiKey = $this->apiKey();
        $oauthKey = (string) config('services.twenti.oauth_client_key', '');

        $combined = $oauthKey !== '' ? $apiKey.'+'.$oauthKey : $apiKey;

        return $this->http
            ->withToken(base64_encode($combined))
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->throw();
    }

    /**
     * Esegue due GET in parallelo e restituisce i due risultati.
     *
     * @param  bool  $hosting  true = usa combined key (Web Hosting API), false = usa solo api_key (Reseller API)
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function parallel(string $urlA, string $urlB, bool $hosting = false): array
    {
        $apiKey = $this->apiKey();
        $oauthKey = (string) config('services.twenti.oauth_client_key', '');
        $combined = ($hosting && $oauthKey !== '') ? $apiKey.'+'.$oauthKey : $apiKey;
        $token = base64_encode($combined);
        $base = $this->baseUrl;

        $responses = $this->http->pool(fn ($pool) => [
            $pool->as('a')->withToken($token)->acceptJson()->get($base.$urlA),
            $pool->as('b')->withToken($token)->acceptJson()->get($base.$urlB),
        ]);

        // Laravel HTTP pool: i singoli elementi possono essere Response oppure
        // un'eccezione (ConnectionException, ecc.) se la chiamata fallisce.
        // Non chiamare ->json() su un'eccezione: lancia "undefined method"
        // che è un Error, non un'Exception, e bypassa il catch standard.
        return [
            $this->jsonOrThrow($responses['a'], $urlA),
            $this->jsonOrThrow($responses['b'], $urlB),
        ];
    }

    /**
     * Estrae il JSON da un'item di pool, propagando come Exception se è
     * stato restituito un Throwable invece di una Response.
     *
     * @return array<string, mixed>
     */
    private function jsonOrThrow(mixed $item, string $url): array
    {
        if ($item instanceof \Throwable) {
            throw new ProviderRequestException("20i parallel call failed for {$url}: ".$item->getMessage(), null, $item);
        }

        return $item->json() ?? [];
    }

    // -- Data mappers ---------------------------------------------------------

    private function mbToBytes(?float $mb): int
    {
        return $mb !== null ? (int) round($mb * 1024 * 1024) : 0;
    }

    private function limitToBytes(mixed $limit): int
    {
        if ($limit === 'INF' || $limit === null || $limit === 0) {
            return 0;
        }

        return (int) round((float) $limit * 1024 * 1024);
    }

    private function bandwidthToBytes(array $bandwidth): int
    {
        $mb = ($bandwidth['MbIn'] ?? 0) + ($bandwidth['MbOut'] ?? 0);

        return (int) round($mb * 1024 * 1024);
    }

    private function parsePhpVersion(string $raw): string
    {
        $clean = trim($raw, '"\'');

        if (preg_match('/^php(\d)(\d+)$/', $clean, $m)) {
            return "{$m[1]}.{$m[2]}";
        }

        return $clean ?: 'unknown';
    }

    private function parseStatus(mixed $enabled): string
    {
        return ($enabled === true || $enabled === 1) ? 'active' : 'suspended';
    }

    private function formatLimit(mixed $value): string
    {
        if ($value === 'INF' || $value === null) {
            return 'Illimitato';
        }

        if ($value === 0 || $value === false) {
            return 'Non incluso';
        }

        return (string) $value;
    }

    // -- Read: Package types --------------------------------------------------

    public function getPackageTypes(): array
    {
        try {
            $response = $this->clientReseller()->get('/reseller/*/packageTypes');
            $types = $response->json() ?? [];
        } catch (Exception $e) {
            Log::error('20i getPackageTypes failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return array_map(fn (array $t): array => $this->normalizePackageType($t), $types);
    }

    private function normalizePackageType(array $t): array
    {
        $limits = $t['limits'] ?? [];

        return [
            'id' => (int) $t['id'],
            'label' => $t['label'] ?? '',
            'disk' => $this->formatLimit($limits['webspace'] ?? null),
            'bandwidth' => $this->formatLimit($limits['bandwidth'] ?? null),
            'mailboxes' => $this->formatLimit($limits['mailboxes'] ?? null),
            'databases' => $this->formatLimit($limits['mysqlDatabases'] ?? null),
            'ssl' => (bool) ($limits['freeSsl'] ?? false),
            'ssh' => (bool) ($limits['ssh'] ?? false),
            'backups' => (bool) ($limits['backups'] ?? false),
        ];
    }

    // -- Read: Packages -------------------------------------------------------

    public function getPackages(): array
    {
        try {
            $response = $this->clientReseller()->get('/package');
            $items = $response->json() ?? [];
        } catch (Exception $e) {
            Log::error('20i getPackages failed', ['error' => $e->getMessage()]);
            throw $e;
        }

        return array_map(fn (array $pkg): array => [
            'id' => (string) ($pkg['id'] ?? ''),
            'name' => $pkg['name'] ?? '',
            'label' => $pkg['name'] ?? '',
            'disk_used' => 0,
            'disk_limit' => 0,
            'bandwidth_used' => 0,
            'bandwidth_limit' => 0,
            'status' => $this->parseStatus($pkg['enabled'] ?? true),
        ], $items);
    }

    public function getPackage(string $packageId): array
    {
        try {
            [$detail, $usage] = $this->parallel(
                "/package/{$packageId}",
                "/package/{$packageId}/web/usage",
                hosting: true,
            );

            $phpRaw = $this->clientHosting()
                ->get("/package/{$packageId}/web/phpVersion")
                ->body();
        } catch (Exception $e) {
            Log::error('20i getPackage failed', ['packageId' => $packageId, 'error' => $e->getMessage()]);
            throw $e;
        }

        $names = $detail['names'] ?? [];
        $limits = $detail['limits'] ?? [];
        $enabled = $detail['enabled'] ?? $detail['status'] ?? true;

        return [
            'id' => (string) ($detail['id'] ?? $packageId),
            'name' => $names[0] ?? '',
            'label' => $detail['web']['label'] ?? ($names[0] ?? ''),
            'disk_used' => $this->mbToBytes($usage['DiskMb'] ?? null),
            'disk_limit' => $this->limitToBytes($limits['webspace'] ?? null),
            'bandwidth_used' => $this->bandwidthToBytes($usage['Bandwidth'] ?? []),
            'bandwidth_limit' => $this->limitToBytes($limits['bandwidth'] ?? null),
            'php_version' => $this->parsePhpVersion($phpRaw),
            'domains' => $names,
            'status' => $this->parseStatus($enabled),
        ];
    }

    // -- Read: Package domains ------------------------------------------------

    public function getPackageDomains(string $packageId): array
    {
        try {
            [$names, $addonDomains] = $this->parallel(
                "/package/{$packageId}/web/names",
                "/package/{$packageId}/domain",
                hosting: true,
            );
        } catch (Exception $e) {
            Log::error('20i getPackageDomains failed', ['packageId' => $packageId, 'error' => $e->getMessage()]);
            throw $e;
        }

        $addonIndex = [];
        if (is_array($addonDomains)) {
            foreach ($addonDomains as $addon) {
                $addonName = $addon['name'] ?? $addon['domain'] ?? null;
                if ($addonName !== null) {
                    $addonIndex[$addonName] = $addon['document_root'] ?? '';
                }
            }
        }

        $allNames = is_array($names) ? $names : [];

        return array_map(fn (string $name): array => [
            'name' => $name,
            'document_root' => $addonIndex[$name] ?? '',
        ], $allNames);
    }

    // -- Write ----------------------------------------------------------------

    public function createPackage(array $data): array
    {
        try {
            $response = $this->clientReseller()->post('/reseller/*/addWeb', [
                'domain_name' => $data['domain'],
                'type' => $data['package_type_id'] ?? config('services.twenti.package_type_id'),
            ]);

            $result = $response->json();
            $packageId = $result['result'] ?? null;

            if ($packageId === null) {
                throw new ProviderRequestException('20i createPackage: risposta non valida — '.json_encode($result), $response->status());
            }

            return [
                'id' => (string) $packageId,
                'status' => 'provisioning',
            ];
        } catch (Exception $e) {
            Log::error('20i createPackage failed', ['data' => $data, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function deletePackage(string $packageId): bool
    {
        throw new UnsupportedOperationException(
            'TwentiService::deletePackage() non deve essere chiamato direttamente. '
            .'L\'endpoint 20i non supporta la cancellazione via API. '
            .'Usare TwentiHybridService o eliminare dal pannello my.20i.com.'
        );
    }

    // -- Domain management ----------------------------------------------------

    public function addDomainToPackage(string $packageId, string $domain): array
    {
        try {
            $response = $this->clientHosting()->post("/package/{$packageId}/web/addName", [
                'name' => $domain,
            ]);

            $result = $response->json();

            return [
                'package_id' => $packageId,
                'domain' => $domain,
                'document_root' => $result['document_root'] ?? '/domains/'.$domain.'/public_html',
            ];
        } catch (Exception $e) {
            Log::error('20i addDomainToPackage failed', ['packageId' => $packageId, 'domain' => $domain, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    // -- Mailbox management ---------------------------------------------------

    public function getMailboxes(string $packageId): array
    {
        try {
            $response = $this->clientHosting()->get("/package/{$packageId}/email/mailboxes");
            $items = $response->json() ?? [];
        } catch (Exception $e) {
            Log::error('20i getMailboxes failed', ['packageId' => $packageId, 'error' => $e->getMessage()]);
            throw $e;
        }

        return array_map(fn (array $m): array => [
            'id' => (string) ($m['id'] ?? ''),
            'address' => $m['email'] ?? '',
            'quota_used' => (int) ($m['quota_used'] ?? 0),
            'quota_limit' => (int) ($m['quota_limit'] ?? 0),
            'status' => ($m['enabled'] ?? true) ? 'active' : 'suspended',
        ], $items);
    }

    public function createMailbox(string $packageId, array $data): array
    {
        try {
            $response = $this->clientHosting()->post("/package/{$packageId}/email/mailboxes", [
                'localPart' => $data['local_part'],
                'domain' => $data['domain'],
                'password' => $data['password'],
                'quota' => ($data['quota_mb'] ?? 1000) * 1024 * 1024,
            ]);

            $result = $response->json();

            return [
                'id' => (string) ($result['id'] ?? ''),
                'address' => $data['local_part'].'@'.$data['domain'],
                'status' => 'active',
            ];
        } catch (Exception $e) {
            Log::error('20i createMailbox failed', ['packageId' => $packageId, 'data' => array_diff_key($data, ['password' => '']), 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function deleteMailbox(string $packageId, string $mailboxId): bool
    {
        try {
            $this->clientHosting()->delete("/package/{$packageId}/email/mailboxes/{$mailboxId}");

            return true;
        } catch (Exception $e) {
            Log::error('20i deleteMailbox failed', ['packageId' => $packageId, 'mailboxId' => $mailboxId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function updateMailboxPassword(string $packageId, string $mailboxId, string $password): bool
    {
        try {
            $this->clientHosting()->put("/package/{$packageId}/email/mailboxes/{$mailboxId}", [
                'password' => $password,
            ]);

            return true;
        } catch (Exception $e) {
            Log::error('20i updateMailboxPassword failed', ['packageId' => $packageId, 'mailboxId' => $mailboxId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    // -- Application installer (WordPress 1-click) ---------------------------

    public function getInstalledApplications(string $packageId): array
    {
        try {
            $response = $this->clientHosting()->get("/package/{$packageId}/web/installedApplications");
            $items = $response->json() ?? [];
        } catch (Exception $e) {
            Log::error('20i getInstalledApplications failed', ['packageId' => $packageId, 'error' => $e->getMessage()]);
            throw $e;
        }

        return array_map(function (array $a): array {
            $domain = (string) ($a['domain'] ?? '');
            $path = (string) ($a['path'] ?? '/');

            return [
                'id' => (string) ($a['id'] ?? ''),
                'name' => (string) ($a['name'] ?? $a['application'] ?? ''),
                'domain' => $domain,
                'path' => $path,
                'status' => (string) ($a['status'] ?? 'installed'),
                'admin_url' => $domain !== ''
                  ? "https://{$domain}".rtrim($path, '/').'/wp-admin'
                  : '',
            ];
        }, is_array($items) ? $items : []);
    }

    public function installApplication(string $packageId, array $data): array
    {
        $domain = $data['domain'];
        $path = $data['path'] ?? '/';

        try {
            $response = $this->clientHosting()->post("/package/{$packageId}/web/installApplication", [
                'name' => $data['app'] ?? 'wordpress',
                'domainName' => $domain,
                'path' => $path,
                'params' => [
                    'adminUsername' => $data['admin_user'],
                    'adminEmail' => $data['admin_email'],
                    'adminPassword' => $data['admin_password'],
                    'siteName' => $data['site_title'],
                ],
            ]);

            $result = $response->json() ?? [];
            $id = (string) ($result['id'] ?? $result['install_id'] ?? '');
            $adminUrl = "https://{$domain}".rtrim($path, '/').'/wp-admin';

            return [
                'id' => $id,
                'status' => (string) ($result['status'] ?? 'installing'),
                'admin_url' => $adminUrl,
            ];
        } catch (Exception $e) {
            Log::error('20i installApplication failed', [
                'packageId' => $packageId,
                'domain' => $domain,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // -- Single Sign-On verso StackCP -----------------------------------------

    public function getSsoUrlForPackage(string $packageId): string
    {
        try {
            // 1. Recupera il primo StackCP user del package (gli endpoint 20i
            //    creano uno user di default per ogni package per compatibility).
            //    Se non c'è, lo creiamo via stackUserList.
            $stackUserId = $this->resolveStackUserForPackage($packageId);

            // 2. Genera un token control-panel per quello user.
            $tokenResponse = $this->clientReseller()->post('/reseller/*/controlPanelTokenForUser', [
                'stackUser' => $stackUserId,
            ]);
            $token = (string) ($tokenResponse->json()['token'] ?? '');

            if ($token === '') {
                throw new ProviderRequestException('20i controlPanelTokenForUser: token vuoto', $tokenResponse->status());
            }

            // 3. Compone l'URL SSO. Il package name serve perché StackCP atterra
            //    direttamente nel contesto giusto.
            $packageName = $this->getPackageName($packageId);
            $ssoResponse = $this->clientReseller()->post('/reseller/*/singleSignOn', [
                'token' => $token,
                'package' => $packageName,
            ]);

            $url = (string) ($ssoResponse->json()['url'] ?? '');

            if ($url === '') {
                throw new ProviderRequestException('20i singleSignOn: url vuoto', $ssoResponse->status());
            }

            return $url;
        } catch (Exception $e) {
            Log::error('20i getSsoUrlForPackage failed', [
                'packageId' => $packageId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Recupera (o crea on-the-fly) lo StackCP user associato al package.
     */
    private function resolveStackUserForPackage(string $packageId): string
    {
        $response = $this->clientReseller()->get("/package/{$packageId}/stackUserList");
        $users = $response->json() ?? [];

        if (! empty($users)) {
            $first = $users[0] ?? [];
            $ref = $first['reference'] ?? $first['id'] ?? null;
            if ($ref !== null) {
                return (string) $ref;
            }
        }

        // Nessuno user esistente: ne creiamo uno per il package.
        $created = $this->clientReseller()->post("/package/{$packageId}/stackUserList");
        $ref = $created->json()['reference'] ?? $created->json()['id'] ?? null;

        if ($ref === null) {
            throw new ProviderRequestException("20i: impossibile creare StackCP user per package {$packageId}", $created->status());
        }

        return (string) $ref;
    }

    /**
     * Risolve il nome del package (richiesto dall'endpoint singleSignOn).
     */
    private function getPackageName(string $packageId): string
    {
        $detail = $this->clientReseller()->get("/package/{$packageId}")->json() ?? [];

        $names = $detail['names'] ?? [];

        if (! empty($names)) {
            return (string) $names[0];
        }

        return (string) ($detail['name'] ?? $packageId);
    }

    // -- SSL Let's Encrypt ----------------------------------------------------

    public function getSslStatus(string $packageId): array
    {
        try {
            $response = $this->clientHosting()->get("/package/{$packageId}/web/ssl");
            $data = $response->json() ?? [];
        } catch (Exception $e) {
            Log::error('20i getSslStatus failed', ['packageId' => $packageId, 'error' => $e->getMessage()]);
            throw $e;
        }

        // 20i risponde con vari shape a seconda dello stato. Normalizziamo:
        $rawStatus = (string) ($data['status'] ?? $data['state'] ?? '');
        $status = match (strtolower($rawStatus)) {
            'active', 'issued', 'ok' => 'active',
            'pending', 'issuing' => 'pending',
            'expired' => 'expired',
            default => 'none',
        };

        return [
            'status' => $status,
            'expires_at' => isset($data['expires_at']) ? (string) $data['expires_at'] : null,
            'issuer' => (string) ($data['issuer'] ?? "Let's Encrypt"),
            'domain' => (string) ($data['domain'] ?? ''),
        ];
    }

    public function requestSslReissue(string $packageId): bool
    {
        try {
            $this->clientHosting()->post("/package/{$packageId}/web/ssl/reissue");

            return true;
        } catch (Exception $e) {
            Log::error('20i requestSslReissue failed', ['packageId' => $packageId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }
}
