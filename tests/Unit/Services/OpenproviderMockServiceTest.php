<?php

declare(strict_types=1);

namespace Hosterix\Providers\Tests\Unit\Services;

use Hosterix\Providers\Contracts\OpenproviderClientInterface;
use Hosterix\Providers\Services\Mock\OpenproviderMockService;
use PHPUnit\Framework\TestCase;

class OpenproviderMockServiceTest extends TestCase
{
    private OpenproviderMockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new OpenproviderMockService;
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(OpenproviderClientInterface::class, $this->service);
    }

    // -- checkAvailability ----------------------------------------------------

    public function test_available_domain_returns_free(): void
    {
        $result = $this->service->checkAvailability('nuovo-dominio.it');

        $this->assertTrue($result['available']);
        $this->assertSame('free', $result['status']);
        $this->assertSame('nuovo-dominio.it', $result['domain']);
    }

    public function test_taken_prefix_returns_not_available(): void
    {
        $result = $this->service->checkAvailability('taken-example.com');

        $this->assertFalse($result['available']);
        $this->assertSame('active', $result['status']);
    }

    public function test_occupied_prefix_returns_not_available(): void
    {
        $result = $this->service->checkAvailability('occupied-site.net');

        $this->assertFalse($result['available']);
    }

    public function test_existing_fake_domain_returns_not_available(): void
    {
        $result = $this->service->checkAvailability('hosterix.it');

        $this->assertFalse($result['available']);
    }

    // -- checkAvailabilityBulk ------------------------------------------------

    public function test_bulk_check_returns_array_of_results(): void
    {
        $results = $this->service->checkAvailabilityBulk([
            'nuovo.it',
            'taken-site.com',
            'hosterix.it',
        ]);

        $this->assertCount(3, $results);
        $this->assertTrue($results[0]['available']);
        $this->assertFalse($results[1]['available']);
        $this->assertFalse($results[2]['available']);
    }

    public function test_bulk_check_empty_returns_empty(): void
    {
        $this->assertSame([], $this->service->checkAvailabilityBulk([]));
    }

    // -- getDomains -----------------------------------------------------------

    public function test_get_domains_returns_fake_list(): void
    {
        $domains = $this->service->getDomains();

        $this->assertNotEmpty($domains);
        $this->assertArrayHasKey('op_id', $domains[0]);
        $this->assertArrayHasKey('name', $domains[0]);
        $this->assertArrayHasKey('status', $domains[0]);
        $this->assertArrayHasKey('expires_at', $domains[0]);
    }

    // -- registerDomain -------------------------------------------------------

    public function test_register_domain_returns_pending(): void
    {
        $result = $this->service->registerDomain([
            'domain' => 'nuovo-test.it',
            'years' => 1,
            'nameservers' => ['ns1.example.com'],
            'registrant' => ['handle' => 'test'],
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertArrayHasKey('op_id', $result);
        $this->assertIsInt($result['op_id']);
    }

    // -- renewDomain ----------------------------------------------------------

    public function test_renew_domain_returns_pending(): void
    {
        $result = $this->service->renewDomain(1001, 2);

        $this->assertSame('pending', $result['status']);
        $this->assertSame(1001, $result['op_id']);
    }

    // -- getDnsRecords --------------------------------------------------------

    public function test_get_dns_records_returns_standard_records(): void
    {
        $records = $this->service->getDnsRecords('hosterix.it');

        $this->assertNotEmpty($records);

        $types = array_column($records, 'type');
        $this->assertContains('A', $types);
        $this->assertContains('MX', $types);
        $this->assertContains('TXT', $types);

        foreach ($records as $record) {
            $this->assertArrayHasKey('type', $record);
            $this->assertArrayHasKey('name', $record);
            $this->assertArrayHasKey('value', $record);
            $this->assertArrayHasKey('ttl', $record);
            $this->assertIsInt($record['ttl']);
        }
    }

    // -- DNS write ------------------------------------------------------------

    public function test_create_dns_record_appends_to_zone(): void
    {
        $before = $this->service->getDnsRecords('hosterix.it');
        $countBefore = count($before);

        $created = $this->service->createDnsRecord('hosterix.it', [
            'type' => 'A',
            'name' => 'blog',
            'value' => '1.2.3.4',
            'ttl' => 600,
        ]);

        $this->assertSame('A', $created['type']);
        $this->assertSame('blog', $created['name']);
        $this->assertSame('1.2.3.4', $created['value']);
        $this->assertSame(600, $created['ttl']);

        $after = $this->service->getDnsRecords('hosterix.it');
        $this->assertCount($countBefore + 1, $after);
    }

    public function test_create_dns_record_normalizes_type_to_upper(): void
    {
        $created = $this->service->createDnsRecord('hosterix.it', [
            'type' => 'cname',
            'name' => 'shop',
            'value' => '@',
        ]);

        $this->assertSame('CNAME', $created['type']);
        $this->assertSame(3600, $created['ttl']);
    }

    public function test_create_dns_record_preserves_prio_for_mx(): void
    {
        $created = $this->service->createDnsRecord('hosterix.it', [
            'type' => 'MX',
            'name' => '@',
            'value' => 'mail2.example.com',
            'prio' => 20,
        ]);

        $this->assertSame(20, $created['prio']);
    }

    public function test_update_dns_record_replaces_existing(): void
    {
        $this->service->createDnsRecord('hosterix.it', [
            'type' => 'A', 'name' => 'app', 'value' => '1.1.1.1', 'ttl' => 3600,
        ]);

        $updated = $this->service->updateDnsRecord(
            'hosterix.it',
            ['type' => 'A', 'name' => 'app', 'value' => '1.1.1.1'],
            ['type' => 'A', 'name' => 'app', 'value' => '2.2.2.2', 'ttl' => 60],
        );

        $this->assertSame('2.2.2.2', $updated['value']);
        $this->assertSame(60, $updated['ttl']);

        $records = $this->service->getDnsRecords('hosterix.it');
        $matching = array_filter($records, fn (array $r): bool => $r['name'] === 'app');
        $this->assertCount(1, $matching, 'Il record vecchio è stato sostituito, non duplicato');
    }

    public function test_update_dns_record_when_old_missing_adds_new(): void
    {
        $countBefore = count($this->service->getDnsRecords('hosterix.it'));

        $this->service->updateDnsRecord(
            'hosterix.it',
            ['type' => 'A', 'name' => 'inesistente', 'value' => '0.0.0.0'],
            ['type' => 'A', 'name' => 'inesistente', 'value' => '9.9.9.9'],
        );

        $countAfter = count($this->service->getDnsRecords('hosterix.it'));
        $this->assertSame($countBefore + 1, $countAfter);
    }

    public function test_delete_dns_record_removes_from_zone(): void
    {
        $this->service->createDnsRecord('hosterix.it', [
            'type' => 'TXT', 'name' => '_verify', 'value' => 'token-xyz',
        ]);

        $countBefore = count($this->service->getDnsRecords('hosterix.it'));

        $ok = $this->service->deleteDnsRecord('hosterix.it', [
            'type' => 'TXT', 'name' => '_verify', 'value' => 'token-xyz',
        ]);

        $this->assertTrue($ok);
        $this->assertCount($countBefore - 1, $this->service->getDnsRecords('hosterix.it'));
    }

    public function test_delete_dns_record_idempotent_when_missing(): void
    {
        $ok = $this->service->deleteDnsRecord('hosterix.it', [
            'type' => 'A', 'name' => 'fantasma', 'value' => '0.0.0.0',
        ]);

        $this->assertTrue($ok);
    }

    public function test_dns_zones_are_independent_per_domain(): void
    {
        $this->service->createDnsRecord('a.it', ['type' => 'A', 'name' => '@', 'value' => '1.1.1.1']);
        $this->service->createDnsRecord('b.it', ['type' => 'A', 'name' => '@', 'value' => '2.2.2.2']);

        $a = $this->service->getDnsRecords('a.it');
        $b = $this->service->getDnsRecords('b.it');

        $aValues = array_column($a, 'value');
        $bValues = array_column($b, 'value');

        $this->assertContains('1.1.1.1', $aValues);
        $this->assertNotContains('1.1.1.1', $bValues);
        $this->assertContains('2.2.2.2', $bValues);
        $this->assertNotContains('2.2.2.2', $aValues);
    }

    // -- transferDomain -------------------------------------------------------

    public function test_transfer_domain_returns_pending_transfer(): void
    {
        $result = $this->service->transferDomain([
            'domain' => 'trasferisco.it',
            'years' => 1,
            'auth_code' => 'AUTH-XYZ',
            'nameservers' => ['ns1.stackdns.com', 'ns2.stackdns.com'],
            'registrant' => ['handle' => 'test'],
        ]);

        $this->assertSame('pending_transfer', $result['status']);
        $this->assertArrayHasKey('op_id', $result);
        $this->assertIsInt($result['op_id']);
    }

    public function test_transfer_domain_returns_random_op_id(): void
    {
        $result1 = $this->service->transferDomain(['domain' => 'a.it', 'years' => 1, 'auth_code' => 'X', 'nameservers' => [], 'registrant' => []]);
        $result2 = $this->service->transferDomain(['domain' => 'b.it', 'years' => 1, 'auth_code' => 'Y', 'nameservers' => [], 'registrant' => []]);

        // Entrambi sono interi nel range valido
        $this->assertGreaterThanOrEqual(5000, $result1['op_id']);
        $this->assertGreaterThanOrEqual(5000, $result2['op_id']);
    }

    // -- updateNameservers ----------------------------------------------------

    public function test_update_nameservers_returns_updated_status(): void
    {
        $result = $this->service->updateNameservers(9001, ['ns1.stackdns.com', 'ns2.stackdns.com']);

        $this->assertSame('updated', $result['status']);
        $this->assertSame(9001, $result['op_id']);
    }

    public function test_update_nameservers_preserves_op_id(): void
    {
        $result = $this->service->updateNameservers(1234, []);

        $this->assertSame(1234, $result['op_id']);
    }
}
