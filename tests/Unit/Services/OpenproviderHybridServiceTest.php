<?php

declare(strict_types=1);

namespace Hosterix\Providers\Tests\Unit\Services;

use Hosterix\Providers\Contracts\OpenproviderClientInterface;
use Hosterix\Providers\Services\Mock\OpenproviderMockService;
use Hosterix\Providers\Services\OpenproviderHybridService;
use Hosterix\Providers\Services\OpenproviderService;
use PHPUnit\Framework\TestCase;

class OpenproviderHybridServiceTest extends TestCase
{
    private OpenproviderHybridService $hybrid;

    private OpenproviderService $real;

    private OpenproviderMockService $mock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->real = $this->createStub(OpenproviderService::class);
        $this->mock = new OpenproviderMockService;

        $this->hybrid = new OpenproviderHybridService($this->real, $this->mock);
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(OpenproviderClientInterface::class, $this->hybrid);
    }

    // -- Read delegates to real -----------------------------------------------

    public function test_check_availability_delegates_to_real(): void
    {
        $expected = ['domain' => 'test.it', 'available' => true, 'status' => 'free'];
        $this->real->method('checkAvailability')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->checkAvailability('test.it'));
    }

    public function test_check_availability_bulk_delegates_to_real(): void
    {
        $expected = [['domain' => 'a.it', 'available' => true, 'status' => 'free']];
        $this->real->method('checkAvailabilityBulk')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->checkAvailabilityBulk(['a.it']));
    }

    public function test_get_domains_delegates_to_real(): void
    {
        $expected = [['op_id' => 1, 'name' => 'x.it', 'status' => 'ACT', 'expires_at' => null]];
        $this->real->method('getDomains')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->getDomains());
    }

    public function test_get_dns_records_delegates_to_real(): void
    {
        $expected = [['type' => 'A', 'name' => '@', 'value' => '1.2.3.4', 'ttl' => 3600]];
        $this->real->method('getDnsRecords')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->getDnsRecords('test.it'));
    }

    // -- Write delegates to mock ----------------------------------------------

    public function test_register_domain_delegates_to_mock(): void
    {
        $result = $this->hybrid->registerDomain([
            'domain' => 'new.it',
            'years' => 1,
            'nameservers' => ['ns1.test.com'],
            'registrant' => ['handle' => 'x'],
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertIsInt($result['op_id']);
    }

    public function test_renew_domain_delegates_to_mock(): void
    {
        $result = $this->hybrid->renewDomain(123, 1);

        $this->assertSame('pending', $result['status']);
        $this->assertSame(123, $result['op_id']);
    }

    public function test_transfer_domain_delegates_to_mock(): void
    {
        $result = $this->hybrid->transferDomain([
            'domain' => 'trasferisco.it',
            'years' => 1,
            'auth_code' => 'AUTH-XYZ',
            'nameservers' => ['ns1.stackdns.com'],
            'registrant' => [],
        ]);

        $this->assertSame('pending_transfer', $result['status']);
        $this->assertIsInt($result['op_id']);
    }

    public function test_update_nameservers_delegates_to_mock(): void
    {
        $result = $this->hybrid->updateNameservers(9001, ['ns1.stackdns.com', 'ns2.stackdns.com']);

        $this->assertSame('updated', $result['status']);
        $this->assertSame(9001, $result['op_id']);
    }

    // -- DNS write delegates to mock (in dev/staging non tocchiamo zone reali) -

    public function test_create_dns_record_delegates_to_mock(): void
    {
        $created = $this->hybrid->createDnsRecord('test.it', [
            'type' => 'A',
            'name' => 'app',
            'value' => '10.0.0.1',
        ]);

        $this->assertSame('A', $created['type']);
        $this->assertSame('app', $created['name']);
        $this->assertSame('10.0.0.1', $created['value']);
    }

    public function test_update_dns_record_delegates_to_mock(): void
    {
        $this->hybrid->createDnsRecord('test.it', [
            'type' => 'A', 'name' => 'app', 'value' => '10.0.0.1',
        ]);

        $updated = $this->hybrid->updateDnsRecord(
            'test.it',
            ['type' => 'A', 'name' => 'app', 'value' => '10.0.0.1'],
            ['type' => 'A', 'name' => 'app', 'value' => '10.0.0.2', 'ttl' => 60],
        );

        $this->assertSame('10.0.0.2', $updated['value']);
        $this->assertSame(60, $updated['ttl']);
    }

    public function test_delete_dns_record_delegates_to_mock(): void
    {
        $this->hybrid->createDnsRecord('test.it', [
            'type' => 'TXT', 'name' => '_x', 'value' => 'tok',
        ]);

        $ok = $this->hybrid->deleteDnsRecord('test.it', [
            'type' => 'TXT', 'name' => '_x', 'value' => 'tok',
        ]);

        $this->assertTrue($ok);
    }
}
