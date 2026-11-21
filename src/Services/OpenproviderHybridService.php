<?php

declare(strict_types=1);

namespace Hosterix\Providers\Services;

use Hosterix\Providers\Contracts\OpenproviderClientInterface;
use Hosterix\Providers\Services\Mock\OpenproviderMockService;

/**
 * Servizio ibrido OpenProvider: lettura su API reale, scrittura su mock.
 *
 * Lettura  → OpenproviderService  (API reale)
 * Scrittura → OpenproviderMockService (simulazione locale)
 */
class OpenproviderHybridService implements OpenproviderClientInterface
{
    public function __construct(
        private readonly OpenproviderService $real,
        private readonly OpenproviderMockService $mock,
    ) {}

    // -- Read → API reale -----------------------------------------------------

    public function checkAvailability(string $domain): array
    {
        return $this->real->checkAvailability($domain);
    }

    public function checkAvailabilityBulk(array $domains): array
    {
        return $this->real->checkAvailabilityBulk($domains);
    }

    public function getDomains(int $offset = 0, int $limit = 100): array
    {
        return $this->real->getDomains($offset, $limit);
    }

    public function getDnsRecords(string $domainName): array
    {
        return $this->real->getDnsRecords($domainName);
    }

    // -- Write → Mock ---------------------------------------------------------

    public function createDnsRecord(string $domainName, array $record): array
    {
        return $this->mock->createDnsRecord($domainName, $record);
    }

    public function updateDnsRecord(string $domainName, array $oldRecord, array $newRecord): array
    {
        return $this->mock->updateDnsRecord($domainName, $oldRecord, $newRecord);
    }

    public function deleteDnsRecord(string $domainName, array $record): bool
    {
        return $this->mock->deleteDnsRecord($domainName, $record);
    }

    public function registerDomain(array $data): array
    {
        return $this->mock->registerDomain($data);
    }

    public function renewDomain(int $opId, int $years): array
    {
        return $this->mock->renewDomain($opId, $years);
    }

    public function transferDomain(array $data): array
    {
        return $this->mock->transferDomain($data);
    }

    public function updateNameservers(int $opId, array $nameservers): array
    {
        return $this->mock->updateNameservers($opId, $nameservers);
    }

    public function getDomainStatus(int $opId): array
    {
        return $this->mock->getDomainStatus($opId);
    }

    public function cancelTransfer(int $opId): array
    {
        return $this->mock->cancelTransfer($opId);
    }
}
