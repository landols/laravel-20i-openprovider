<?php

declare(strict_types=1);

namespace Hosterix\Providers\Services\Mock;

use Hosterix\Providers\Contracts\TwentiClientInterface;
use RuntimeException;

/**
 * Mock dell'API 20i per sviluppo e testing.
 */
class TwentiMockService implements TwentiClientInterface
{
    /**
     * Mailbox per package: package_id → array di mailbox.
     * Seminate nel costruttore perché PHP non ammette chiamate a funzione
     * negli inizializzatori di proprietà di classe.
     *
     * @var array<string, array<int, array{id: string, address: string, quota_used: int, quota_limit: int, status: string}>>
     */
    private array $fakeMailboxes = [];

    public function __construct()
    {
        $this->fakeMailboxes = [
            'pkg-001' => [
                ['id' => 'mbx-'.substr(md5('infohosterix.it'), 0, 8), 'address' => 'info@hosterix.it', 'quota_used' => 12 * 1024 * 1024, 'quota_limit' => 1000 * 1024 * 1024, 'status' => 'active'],
                ['id' => 'mbx-'.substr(md5('noreplyhosterix.it'), 0, 8), 'address' => 'noreply@hosterix.it', 'quota_used' => 0, 'quota_limit' => 1000 * 1024 * 1024, 'status' => 'active'],
            ],
        ];
    }

    /** @var array<int, array<string, mixed>> */
    private array $fakePackages = [
        [
            'id' => 'pkg-001',
            'name' => 'hosterix.it',
            'label' => 'Hosterix Main',
            'disk_used' => 512 * 1024 * 1024,
            'disk_limit' => 10 * 1024 * 1024 * 1024,
            'bandwidth_used' => 1200 * 1024 * 1024,
            'bandwidth_limit' => 100 * 1024 * 1024 * 1024,
            'php_version' => '8.4',
            'domains' => ['hosterix.it', 'www.hosterix.it'],
            'status' => 'active',
        ],
        [
            'id' => 'pkg-002',
            'name' => 'cliente-demo.net',
            'label' => 'Demo Cliente',
            'disk_used' => 200 * 1024 * 1024,
            'disk_limit' => 5 * 1024 * 1024 * 1024,
            'bandwidth_used' => 300 * 1024 * 1024,
            'bandwidth_limit' => 50 * 1024 * 1024 * 1024,
            'php_version' => '8.3',
            'domains' => ['cliente-demo.net', 'www.cliente-demo.net', 'blog.cliente-demo.net'],
            'status' => 'active',
        ],
        [
            'id' => 'pkg-003',
            'name' => 'vecchio-sito.it',
            'label' => 'Sito Vecchio',
            'disk_used' => 4800 * 1024 * 1024,
            'disk_limit' => 5 * 1024 * 1024 * 1024,
            'bandwidth_used' => 0,
            'bandwidth_limit' => 20 * 1024 * 1024 * 1024,
            'php_version' => '7.4',
            'domains' => ['vecchio-sito.it'],
            'status' => 'suspended',
        ],
    ];

    public function getPackageTypes(): array
    {
        return [
            ['id' => 285093, 'label' => 'Linux Unlimited', 'disk' => 'Illimitato', 'bandwidth' => 'Illimitato', 'mailboxes' => 'Illimitato', 'databases' => 'Illimitato', 'ssl' => true, 'ssh' => true, 'backups' => true],
            ['id' => 285097, 'label' => 'WordPress Unlimited', 'disk' => 'Illimitato', 'bandwidth' => 'Illimitato', 'mailboxes' => 'Illimitato', 'databases' => 'Illimitato', 'ssl' => true, 'ssh' => false, 'backups' => true],
            ['id' => 285099, 'label' => 'Web Builder', 'disk' => 'Illimitato', 'bandwidth' => 'Illimitato', 'mailboxes' => 'Illimitato', 'databases' => 'Non incluso', 'ssl' => true, 'ssh' => false, 'backups' => false],
        ];
    }

    public function getPackages(): array
    {
        return array_map(
            fn (array $p): array => array_diff_key($p, array_flip(['php_version', 'domains'])),
            $this->fakePackages,
        );
    }

    public function getPackage(string $packageId): array
    {
        foreach ($this->fakePackages as $pkg) {
            if ($pkg['id'] === $packageId) {
                return $pkg;
            }
        }

        throw new RuntimeException("Pacchetto {$packageId} non trovato.");
    }

    public function getPackageDomains(string $packageId): array
    {
        $package = $this->getPackage($packageId);

        return array_map(fn (string $domain): array => [
            'name' => $domain,
            'document_root' => '/domains/'.$domain.'/public_html',
        ], $package['domains']);
    }

    public function createPackage(array $data): array
    {
        return [
            'id' => 'pkg-'.substr(md5($data['domain']), 0, 6),
            'status' => 'provisioning',
        ];
    }

    public function deletePackage(string $packageId): bool
    {
        return true;
    }

    public function addDomainToPackage(string $packageId, string $domain): array
    {
        return [
            'package_id' => $packageId,
            'domain' => $domain,
            'document_root' => '/domains/'.$domain.'/public_html',
        ];
    }

    public function getMailboxes(string $packageId): array
    {
        return $this->fakeMailboxes[$packageId] ?? [];
    }

    public function createMailbox(string $packageId, array $data): array
    {
        $id = 'mbx-'.substr(md5($data['local_part'].$data['domain']), 0, 8);
        $address = $data['local_part'].'@'.$data['domain'];
        $quotaBytes = ($data['quota_mb'] ?? 1000) * 1024 * 1024;

        $mailbox = [
            'id' => $id,
            'address' => $address,
            'quota_used' => 0,
            'quota_limit' => $quotaBytes,
            'status' => 'active',
        ];

        $this->fakeMailboxes[$packageId][] = $mailbox;

        return [
            'id' => $id,
            'address' => $address,
            'status' => 'active',
        ];
    }

    public function deleteMailbox(string $packageId, string $mailboxId): bool
    {
        $this->fakeMailboxes[$packageId] = array_values(
            array_filter(
                $this->fakeMailboxes[$packageId] ?? [],
                fn (array $m): bool => $m['id'] !== $mailboxId,
            ),
        );

        return true;
    }

    public function updateMailboxPassword(string $packageId, string $mailboxId, string $password): bool
    {
        return true;
    }

    /**
     * Applicazioni installate per package_id.
     *
     * @var array<string, array<int, array{id: string, name: string, domain: string, path: string, status: string, admin_url: string}>>
     */
    private array $fakeApps = [];

    public function getInstalledApplications(string $packageId): array
    {
        return $this->fakeApps[$packageId] ?? [];
    }

    public function installApplication(string $packageId, array $data): array
    {
        $domain = $data['domain'];
        $path = $data['path'] ?? '/';
        $id = 'app-'.substr(md5($packageId.$domain.$path), 0, 8);
        $adminUrl = "https://{$domain}".rtrim($path, '/').'/wp-admin';

        $app = [
            'id' => $id,
            'name' => $data['app'] ?? 'wordpress',
            'domain' => $domain,
            'path' => $path,
            'status' => 'installed',
            'admin_url' => $adminUrl,
        ];

        $this->fakeApps[$packageId][] = $app;

        return [
            'id' => $id,
            'status' => 'installed',
            'admin_url' => $adminUrl,
        ];
    }

    public function getSsoUrlForPackage(string $packageId): string
    {
        // URL fittizio per dev. In prod sarebbe un real SSO StackCP brandizzato.
        // Token "scade" tra 60s simulando il comportamento reale: usiamo il
        // timestamp corrente per dare l'idea di unicità.
        $fakeToken = substr(md5($packageId.microtime()), 0, 32);

        return "https://example.com/fake-stackcp?package={$packageId}&token={$fakeToken}";
    }

    /**
     * Stato SSL per package: in-memory, reset alla riemissione mock.
     *
     * @var array<string, array{status: string, expires_at: ?string}>
     */
    private array $fakeSsl = [];

    public function getSslStatus(string $packageId): array
    {
        $package = null;
        foreach ($this->fakePackages as $p) {
            if ($p['id'] === $packageId) {
                $package = $p;
                break;
            }
        }

        $domain = $package['name'] ?? 'esempio.it';

        // Default: certificato attivo che scade fra 60 giorni (rinnovo automatico
        // a 30 nel comportamento Let's Encrypt). Override via fakeSsl per test.
        $state = $this->fakeSsl[$packageId] ?? [
            'status' => 'active',
            'expires_at' => now()->addDays(60)->toIso8601String(),
        ];

        return [
            'status' => $state['status'],
            'expires_at' => $state['expires_at'],
            'issuer' => "Let's Encrypt",
            'domain' => $domain,
        ];
    }

    public function requestSslReissue(string $packageId): bool
    {
        // Simula passaggio a 'pending' che poi torna 'active' alla prossima
        // chiamata. Nel test si può forzare lo stato via property.
        $this->fakeSsl[$packageId] = [
            'status' => 'pending',
            'expires_at' => null,
        ];

        return true;
    }
}
