<?php

declare(strict_types=1);

namespace Hosterix\Providers\Contracts;

interface TwentiClientInterface
{
    /**
     * Lista dei piani hosting disponibili sul reseller.
     *
     * @return array<int, array{id: int, label: string, disk: string, bandwidth: string, mailboxes: string, databases: string, ssl: bool, ssh: bool, backups: bool}>
     */
    public function getPackageTypes(): array;

    /**
     * Lista dei pacchetti hosting dell'account.
     *
     * @return array<int, array{id: string, name: string, label: string, disk_used: int, disk_limit: int, bandwidth_used: int, bandwidth_limit: int, status: string}>
     */
    public function getPackages(): array;

    /**
     * Dettaglio di un pacchetto hosting.
     *
     * @return array{id: string, name: string, label: string, disk_used: int, disk_limit: int, bandwidth_used: int, bandwidth_limit: int, php_version: string, domains: string[], status: string}
     */
    public function getPackage(string $packageId): array;

    /**
     * Lista dei domini associati a un pacchetto.
     *
     * @return array<int, array{name: string, document_root: string}>
     */
    public function getPackageDomains(string $packageId): array;

    /**
     * Crea un nuovo pacchetto hosting.
     *
     * @param  array{label: string, domain: string, stack_user: string}  $data
     * @return array{id: string, status: string}
     */
    public function createPackage(array $data): array;

    /**
     * Elimina un pacchetto hosting.
     */
    public function deletePackage(string $packageId): bool;

    /**
     * Aggiunge un dominio a un pacchetto hosting esistente.
     *
     * @return array{package_id: string, domain: string, document_root: string}
     */
    public function addDomainToPackage(string $packageId, string $domain): array;

    /**
     * Lista delle caselle email di un pacchetto.
     *
     * @return array<int, array{id: string, address: string, quota_used: int, quota_limit: int, status: string}>
     */
    public function getMailboxes(string $packageId): array;

    /**
     * Crea una nuova casella email in un pacchetto.
     *
     * @param  array{local_part: string, domain: string, password: string, quota_mb: int}  $data
     * @return array{id: string, address: string, status: string}
     */
    public function createMailbox(string $packageId, array $data): array;

    /**
     * Elimina una casella email.
     */
    public function deleteMailbox(string $packageId, string $mailboxId): bool;

    /**
     * Aggiorna la password di una casella email.
     */
    public function updateMailboxPassword(string $packageId, string $mailboxId, string $password): bool;

    /**
     * Elenca le applicazioni installate sul pacchetto (WordPress, Joomla, etc.).
     *
     * @return array<int, array{
     *   id: string,
     *   name: string,
     *   domain: string,
     *   path: string,
     *   status: string,
     *   admin_url: string
     * }>
     */
    public function getInstalledApplications(string $packageId): array;

    /**
     * Installa una web application (WordPress per il MVP) sul pacchetto.
     *
     * Lo status restituito è 'installing' durante il provisioning asincrono e
     * diventa 'installed' al completamento (rilevabile con
     * getInstalledApplications).
     *
     * @param  array{
     *   app: string,
     *   domain: string,
     *   path?: string,
     *   admin_user: string,
     *   admin_email: string,
     *   admin_password: string,
     *   site_title: string
     * }  $data
     * @return array{id: string, status: string, admin_url: string}
     */
    public function installApplication(string $packageId, array $data): array;

    /**
     * Genera un URL di Single Sign-On per accedere al pannello StackCP
     * brandizzato del cliente. L'URL contiene un token one-shot di breve
     * durata (~60s). Non deve essere mostrato all'utente come stringa: va
     * usato come destinazione di un redirect server-side.
     *
     * Nel MVP supportiamo un solo "StackCP user" per pacchetto, creato al
     * volo se non esiste. Pattern interno:
     *   1. risolve / crea StackCP user per il package
     *   2. richiede controlPanelTokenForUser per quello user
     *   3. compone l'URL via singleSignOn
     *
     * Da usare nel flow "Pannello avanzato".
     */
    public function getSsoUrlForPackage(string $packageId): string;

    /**
     * Stato del certificato SSL Let's Encrypt sul package.
     *
     * status normalizzato:
     *  - 'active'  → certificato emesso e valido
     *  - 'pending' → emissione in corso (DNS che si propaga, validation)
     *  - 'expired' → certificato scaduto, va riemesso
     *  - 'none'    → nessun certificato attivo (free SSL non applicato)
     *
     * expires_at è null per status diversi da active/expired.
     *
     * @return array{
     *   status: string,
     *   expires_at: ?string,
     *   issuer: string,
     *   domain: string
     * }
     */
    public function getSslStatus(string $packageId): array;

    /**
     * Richiede la riemissione del certificato Let's Encrypt sul package.
     * Operazione asincrona: il provider esegue la validation e la nuova
     * emissione entro pochi minuti. Lo status passa a 'pending' e poi torna
     * 'active' al completamento (rilevabile con getSslStatus).
     */
    public function requestSslReissue(string $packageId): bool;
}
