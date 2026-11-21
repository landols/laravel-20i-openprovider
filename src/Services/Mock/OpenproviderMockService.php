<?php

declare(strict_types=1);

namespace Hosterix\Providers\Services\Mock;

use Hosterix\Providers\Contracts\OpenproviderClientInterface;

/**
 * Mock dell'API OpenProvider per sviluppo e testing.
 *
 * Regole simulate:
 *  - Domini che iniziano con "taken" o "occupied" → non disponibili
 *  - Tutti gli altri → disponibili
 *  - registerDomain() → sempre OK con op_id fake
 *  - Le zone DNS sono in-memory per istanza: utili per test CRUD ma non
 *    persistono tra request HTTP. In feature test, instanziare il mock una
 *    volta e tenerlo come singleton via $this->app->instance().
 */
class OpenproviderMockService implements OpenproviderClientInterface
{
    /** @var array<int, array{op_id: int, name: string, status: string, expires_at: string}> */
    private array $fakeDomains = [
        ['op_id' => 1001, 'name' => 'hosterix.it', 'status' => 'ACT', 'expires_at' => '2027-03-18T00:00:00Z'],
        ['op_id' => 1002, 'name' => 'hosterix.com', 'status' => 'ACT', 'expires_at' => '2026-09-01T00:00:00Z'],
        ['op_id' => 1003, 'name' => 'cliente-demo.net', 'status' => 'ACT', 'expires_at' => '2026-05-15T00:00:00Z'],
        ['op_id' => 1004, 'name' => 'scaduto-demo.it', 'status' => 'EXP', 'expires_at' => '2025-12-01T00:00:00Z'],
    ];

    /**
     * Store DNS in-memory: zone[domain] = lista record con chiavi normalizzate.
     *
     * @var array<string, array<int, array{type: string, name: string, value: string, ttl: int, prio?: int}>>
     */
    private array $dnsZones = [];

    public function checkAvailability(string $domain): array
    {
        $lower = strtolower($domain);
        $available = ! str_starts_with($lower, 'taken')
          && ! str_starts_with($lower, 'occupied')
          && ! in_array($lower, array_column($this->fakeDomains, 'name'), true);

        return [
            'domain' => $domain,
            'available' => $available,
            'status' => $available ? 'free' : 'active',
        ];
    }

    public function checkAvailabilityBulk(array $domains): array
    {
        return array_map(fn (string $d): array => $this->checkAvailability($d), $domains);
    }

    public function getDomains(int $offset = 0, int $limit = 100): array
    {
        return $this->fakeDomains;
    }

    public function registerDomain(array $data): array
    {
        return [
            'op_id' => random_int(5000, 9999),
            'status' => 'pending',
        ];
    }

    public function renewDomain(int $opId, int $years): array
    {
        return [
            'op_id' => $opId,
            'status' => 'pending',
        ];
    }

    public function getDnsRecords(string $domainName): array
    {
        return $this->dnsZones[$domainName] ??= $this->seedZone($domainName);
    }

    public function createDnsRecord(string $domainName, array $record): array
    {
        $zone = $this->getDnsRecords($domainName);
        $normalized = $this->normalizeRecord($record);

        $zone[] = $normalized;
        $this->dnsZones[$domainName] = $zone;

        return $normalized;
    }

    public function updateDnsRecord(string $domainName, array $oldRecord, array $newRecord): array
    {
        $zone = $this->getDnsRecords($domainName);
        $normalized = $this->normalizeRecord($newRecord);

        $found = false;
        foreach ($zone as $i => $r) {
            if ($this->recordMatches($r, $oldRecord)) {
                $zone[$i] = $normalized;
                $found = true;
                break;
            }
        }

        if (! $found) {
            $zone[] = $normalized;
        }

        $this->dnsZones[$domainName] = $zone;

        return $normalized;
    }

    public function deleteDnsRecord(string $domainName, array $record): bool
    {
        $zone = $this->getDnsRecords($domainName);

        $this->dnsZones[$domainName] = array_values(array_filter(
            $zone,
            fn (array $r): bool => ! $this->recordMatches($r, $record),
        ));

        return true;
    }

    /**
     * @return array<int, array{type: string, name: string, value: string, ttl: int, prio?: int}>
     */
    private function seedZone(string $domainName): array
    {
        return [
            ['type' => 'A', 'name' => '@', 'value' => '185.230.63.107', 'ttl' => 3600],
            ['type' => 'A', 'name' => 'www', 'value' => '185.230.63.107', 'ttl' => 3600],
            ['type' => 'MX', 'name' => '@', 'value' => 'mail.hosterix.net', 'ttl' => 3600, 'prio' => 10],
            ['type' => 'TXT', 'name' => '@', 'value' => 'v=spf1 include:hosterix.net ~all', 'ttl' => 3600],
            ['type' => 'CNAME', 'name' => 'ftp', 'value' => '@', 'ttl' => 3600],
        ];
    }

    /**
     * @param  array{type?: string, name?: string, value?: string, ttl?: int, prio?: int}  $r
     * @return array{type: string, name: string, value: string, ttl: int, prio?: int}
     */
    private function normalizeRecord(array $r): array
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
     * Match identity di un record DNS via (type, name, value). Case-insensitive
     * sul type, esatto su name e value.
     *
     * @param  array{type: string, name: string, value: string}  $a
     * @param  array{type: string, name: string, value: string}  $b
     */
    private function recordMatches(array $a, array $b): bool
    {
        return strtoupper($a['type']) === strtoupper($b['type'])
          && $a['name'] === $b['name']
          && $a['value'] === $b['value'];
    }

    public function transferDomain(array $data): array
    {
        return [
            'op_id' => random_int(5000, 9999),
            'status' => 'pending_transfer',
        ];
    }

    public function updateNameservers(int $opId, array $nameservers): array
    {
        return [
            'op_id' => $opId,
            'status' => 'updated',
        ];
    }

    public function getDomainStatus(int $opId): array
    {
        // Mock: il dominio è sempre "active" (stato terminale). Per simulare
        // pending_transfer in test, sostituire questo client con un mock PHPUnit.
        return [
            'op_id' => $opId,
            'status' => 'active',
        ];
    }

    public function cancelTransfer(int $opId): array
    {
        return [
            'op_id' => $opId,
            'status' => 'cancelled',
        ];
    }
}
