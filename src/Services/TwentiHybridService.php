<?php

declare(strict_types=1);

namespace Hosterix\Providers\Services;

use Hosterix\Providers\Contracts\TwentiClientInterface;
use Hosterix\Providers\Services\Mock\TwentiMockService;

/**
 * Servizio ibrido 20i per sviluppo e staging: lettura su API reale, scrittura su mock.
 *
 * Lettura  → TwentiService  (API reale 20i)
 * Scrittura → TwentiMockService (simulazione locale)
 *
 * Eccezioni:
 * - createPackage() scrive sull'API reale: il pacchetto serve davvero per provare
 *   il resto del flusso (domini, caselle, applicazioni) contro 20i.
 * - deletePackage() usa sempre il mock, perché 20i non supporta la cancellazione via API.
 * - getSsoUrlForPackage() usa il mock: senza credenziali reali genera un URL fittizio.
 */
class TwentiHybridService implements TwentiClientInterface
{
    public function __construct(
        private readonly TwentiService $real,
        private readonly TwentiMockService $mock,
    ) {}

    // -- Read → API reale -----------------------------------------------------

    public function getPackageTypes(): array
    {
        return $this->real->getPackageTypes();
    }

    public function getPackages(): array
    {
        return $this->real->getPackages();
    }

    public function getPackage(string $packageId): array
    {
        return $this->real->getPackage($packageId);
    }

    public function getPackageDomains(string $packageId): array
    {
        return $this->real->getPackageDomains($packageId);
    }

    // -- Write ----------------------------------------------------------------

    public function createPackage(array $data): array
    {
        return $this->real->createPackage($data);
    }

    public function deletePackage(string $packageId): bool
    {
        return $this->mock->deletePackage($packageId);
    }

    // -- Domain management: write → mock (staging safe) -----------------------

    public function addDomainToPackage(string $packageId, string $domain): array
    {
        return $this->mock->addDomainToPackage($packageId, $domain);
    }

    // -- Mailbox: read → real, write → mock -----------------------------------

    public function getMailboxes(string $packageId): array
    {
        return $this->real->getMailboxes($packageId);
    }

    public function createMailbox(string $packageId, array $data): array
    {
        return $this->mock->createMailbox($packageId, $data);
    }

    public function deleteMailbox(string $packageId, string $mailboxId): bool
    {
        return $this->mock->deleteMailbox($packageId, $mailboxId);
    }

    public function updateMailboxPassword(string $packageId, string $mailboxId, string $password): bool
    {
        return $this->mock->updateMailboxPassword($packageId, $mailboxId, $password);
    }

    // -- Application installer: read → real, write → mock --------------------

    public function getInstalledApplications(string $packageId): array
    {
        return $this->real->getInstalledApplications($packageId);
    }

    public function installApplication(string $packageId, array $data): array
    {
        return $this->mock->installApplication($packageId, $data);
    }

    // -- SSO StackCP: mock in dev/staging, real in prod via env switch -------
    // In dev non abbiamo credenziali 20i: il real fallirebbe. Il mock genera
    // un URL example.com fittizio che permette di verificare il flow di
    // redirect lato Hosterix (il "Pannello avanzato" apre nuova tab e atterra
    // su una URL diversa, dimostrando che il bottone funziona). In prod si
    // usa OPENPROVIDER_MOCK=false / TWENTI_MOCK=false con credenziali reali,
    // e il mock viene scavalcato dal binding del ServiceProvider.

    public function getSsoUrlForPackage(string $packageId): string
    {
        return $this->mock->getSsoUrlForPackage($packageId);
    }

    // -- SSL: read → real (lo stato vero del certificato è utile in dev),
    //         write → mock (no senso riemettere certificati reali in dev) -----

    public function getSslStatus(string $packageId): array
    {
        return $this->real->getSslStatus($packageId);
    }

    public function requestSslReissue(string $packageId): bool
    {
        return $this->mock->requestSslReissue($packageId);
    }
}
