<?php

declare(strict_types=1);

namespace Hosterix\Providers\Contracts;

interface OpenproviderClientInterface
{
    /**
     * Verifica la disponibilità di un singolo dominio.
     *
     * @return array{domain: string, available: bool, status: string}
     */
    public function checkAvailability(string $domain): array;

    /**
     * Verifica la disponibilità di più domini in un'unica chiamata.
     *
     * @param  string[]  $domains
     * @return array<int, array{domain: string, available: bool, status: string}>
     */
    public function checkAvailabilityBulk(array $domains): array;

    /**
     * Lista dei domini registrati sull'account.
     *
     * @return array<int, array{op_id: int, name: string, status: string, expires_at: string|null}>
     */
    public function getDomains(int $offset = 0, int $limit = 100): array;

    /**
     * Registra un nuovo dominio.
     *
     * @param  array{domain: string, years: int, nameservers: string[], registrant: array<string, mixed>}  $data
     * @return array{op_id: int, status: string}
     */
    public function registerDomain(array $data): array;

    /**
     * Rinnova un dominio esistente.
     *
     * @return array{op_id: int, status: string}
     */
    public function renewDomain(int $opId, int $years): array;

    /**
     * Recupera i record DNS di un dominio.
     *
     * @return array<int, array{type: string, name: string, value: string, ttl: int, prio?: int}>
     */
    public function getDnsRecords(string $domainName): array;

    /**
     * Aggiunge un record DNS alla zona del dominio.
     *
     * OpenProvider non assegna ID stabili ai singoli record: la modifica avviene
     * sostituendo la zona via PUT con un set di "add" e "remove". Questo metodo
     * incapsula quel pattern esponendo un'interfaccia CRUD-like.
     *
     * @param  array{type: string, name: string, value: string, ttl?: int, prio?: int}  $record
     * @return array{type: string, name: string, value: string, ttl: int}
     */
    public function createDnsRecord(string $domainName, array $record): array;

    /**
     * Modifica un record DNS. Il vecchio record viene identificato dalla tripla
     * (type, name, value): il provider lo rimuove e aggiunge il nuovo.
     *
     * @param  array{type: string, name: string, value: string}  $oldRecord
     * @param  array{type: string, name: string, value: string, ttl?: int, prio?: int}  $newRecord
     * @return array{type: string, name: string, value: string, ttl: int}
     */
    public function updateDnsRecord(string $domainName, array $oldRecord, array $newRecord): array;

    /**
     * Elimina un record DNS dalla zona. Identifica il record dalla tripla
     * (type, name, value). Idempotente: se il record non esiste, ritorna true.
     *
     * @param  array{type: string, name: string, value: string}  $record
     */
    public function deleteDnsRecord(string $domainName, array $record): bool;

    /**
     * Avvia il trasferimento di un dominio esistente.
     *
     * @param  array{domain: string, years: int, auth_code: string, nameservers: string[], registrant: array<string, mixed>}  $data
     * @return array{op_id: int, status: string}
     */
    public function transferDomain(array $data): array;

    /**
     * Recupera lo stato corrente di un dominio (per reconcile asincrono di
     * registrazioni e trasferimenti pending).
     *
     * Status normalizzati attesi: 'active', 'pending', 'pending_transfer',
     * 'failed', 'request_failed'.
     *
     * @return array{op_id: int, status: string}
     */
    public function getDomainStatus(int $opId): array;

    /**
     * Annulla un trasferimento in stato pending. Idempotente: se il transfer
     * è già completato o cancellato, non solleva eccezioni e ritorna lo stato
     * corrente.
     *
     * @return array{op_id: int, status: string}
     */
    public function cancelTransfer(int $opId): array;

    /**
     * Aggiorna i nameservers di un dominio registrato su OpenProvider.
     *
     * @param  string[]  $nameservers
     * @return array{op_id: int, status: string}
     */
    public function updateNameservers(int $opId, array $nameservers): array;
}
