<?php

declare(strict_types=1);

namespace Hosterix\Providers\Tests\Unit\Services;

use Hosterix\Providers\Contracts\OpenproviderClientInterface;
use Hosterix\Providers\Exceptions\ProviderAuthenticationException;
use Hosterix\Providers\Exceptions\ProviderRequestException;
use Hosterix\Providers\Services\OpenproviderService;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Orchestra\Testbench\TestCase;

class OpenproviderServiceTest extends TestCase
{
    private const API = 'https://api.openprovider.test';

    private Factory $http;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openprovider.api_url' => self::API.'/',
            'openprovider.username' => 'reseller',
            'openprovider.password' => 'secret',
        ]);

        $this->http = new Factory;
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    private function service(array $routes = []): OpenproviderService
    {
        $this->http->fake(array_merge([
            self::API.'/v1beta/auth/login' => Factory::response(['code' => 0, 'data' => ['token' => 'tok-123']]),
        ], $routes));

        return new OpenproviderService($this->http);
    }

    private function loginCount(): int
    {
        return count($this->http->recorded(
            fn (Request $r): bool => str_ends_with($r->url(), '/v1beta/auth/login')
        ));
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(OpenproviderClientInterface::class, $this->service());
    }

    public function test_does_not_call_the_api_until_first_use(): void
    {
        $this->service();

        $this->http->assertNothingSent();
    }

    public function test_logs_in_once_and_sends_the_token_on_every_request(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/check' => Factory::response(['code' => 0, 'data' => ['results' => [['status' => 'free']]]]),
        ]);

        $service->checkAvailability('esempio.it');
        $service->checkAvailability('esempio.com');

        $this->assertSame(1, $this->loginCount());
        $this->http->assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/v1beta/auth/login')
          && $r['username'] === 'reseller'
          && $r['password'] === 'secret');
        $this->http->assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/v1beta/domains/check')
          && $r->hasHeader('Authorization', 'Bearer tok-123'));
    }

    public function test_failed_login_raises_an_authentication_exception(): void
    {
        $this->http->fake([
            self::API.'/v1beta/auth/login' => Factory::response(['code' => 196, 'desc' => 'Authentication failed']),
        ]);
        $service = new OpenproviderService($this->http);

        $this->expectException(ProviderAuthenticationException::class);
        $this->expectExceptionMessage('OpenProvider login failed: Authentication failed');

        $service->getDomains();
    }

    public function test_check_availability_splits_the_domain_and_maps_the_status(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/check' => Factory::response(['code' => 0, 'data' => ['results' => [['status' => 'active']]]]),
        ]);

        $result = $service->checkAvailability('esempio.co.uk');

        $this->assertSame(['domain' => 'esempio.co.uk', 'available' => false, 'status' => 'active'], $result);
        $this->http->assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/v1beta/domains/check')
          && $r['domains'] === [['name' => 'esempio', 'extension' => 'co.uk']]);
    }

    public function test_check_availability_bulk_returns_one_entry_per_domain(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/check' => Factory::response(['code' => 0, 'data' => ['results' => [
                ['domain' => 'a.it', 'status' => 'free'],
                ['domain' => 'b.it', 'status' => 'active'],
            ]]]),
        ]);

        $this->assertSame([
            ['domain' => 'a.it', 'available' => true, 'status' => 'free'],
            ['domain' => 'b.it', 'available' => false, 'status' => 'active'],
        ], $service->checkAvailabilityBulk(['a.it', 'b.it']));
    }

    public function test_check_availability_bulk_with_no_domains_makes_no_request(): void
    {
        $this->assertSame([], $this->service()->checkAvailabilityBulk([]));
        $this->http->assertNothingSent();
    }

    public function test_get_domains_maps_name_status_and_expiry(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains*' => Factory::response(['code' => 0, 'data' => ['results' => [
                ['id' => 42, 'domain' => ['name' => 'esempio', 'extension' => 'it'], 'status' => 'ACT', 'expiration_date' => '2027-01-01 00:00:00'],
            ]]]),
        ]);

        $this->assertSame([
            ['op_id' => 42, 'name' => 'esempio.it', 'status' => 'ACT', 'expires_at' => '2027-01-01 00:00:00'],
        ], $service->getDomains(10, 5));
        $this->http->assertSent(fn (Request $r): bool => str_contains($r->url(), '/v1beta/domains?')
          && str_contains($r->url(), 'offset=10') && str_contains($r->url(), 'limit=5'));
    }

    public function test_register_domain_sends_nameservers_and_owner_handle(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains' => Factory::response(['code' => 0, 'data' => ['id' => 77]]),
        ]);

        $result = $service->registerDomain([
            'domain' => 'esempio.it',
            'years' => 2,
            'nameservers' => ['ns1.example.net', 'ns2.example.net'],
            'registrant' => ['handle' => 'XX000001-IT'],
        ]);

        $this->assertSame(['op_id' => 77, 'status' => 'pending'], $result);
        $this->http->assertSent(fn (Request $r): bool => $r->method() === 'POST'
          && str_ends_with($r->url(), '/v1beta/domains')
          && $r['domain'] === ['name' => 'esempio', 'extension' => 'it']
          && $r['period'] === 2
          && $r['name_servers'] === [['name' => 'ns1.example.net'], ['name' => 'ns2.example.net']]
          && $r['owner_handle'] === 'XX000001-IT');
    }

    public function test_api_error_raises_a_request_exception_with_the_provider_message(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/77/renew' => Factory::response(['code' => 320, 'desc' => 'Domain is not renewable'], 400),
        ]);

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('OpenProvider /v1beta/domains/77/renew error: Domain is not renewable');

        $service->renewDomain(77, 1);
    }

    public function test_renew_domain_returns_the_provider_status(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/77/renew' => Factory::response(['code' => 0, 'data' => ['status' => 'ACT']]),
        ]);

        $this->assertSame(['op_id' => 77, 'status' => 'ACT'], $service->renewDomain(77, 1));
    }

    public function test_get_dns_records_maps_records_and_keeps_priority(): void
    {
        $service = $this->service([
            self::API.'/v1beta/dns/zones/esempio.it/records*' => Factory::response(['code' => 0, 'data' => ['results' => [
                ['type' => 'A', 'name' => '@', 'value' => '192.0.2.1', 'ttl' => 600],
                ['type' => 'MX', 'name' => '@', 'value' => 'mail.esempio.it', 'ttl' => 3600, 'prio' => 10],
            ]]]),
        ]);

        $this->assertSame([
            ['type' => 'A', 'name' => '@', 'value' => '192.0.2.1', 'ttl' => 600],
            ['type' => 'MX', 'name' => '@', 'value' => 'mail.esempio.it', 'ttl' => 3600, 'prio' => 10],
        ], $service->getDnsRecords('esempio.it'));
    }

    public function test_get_dns_records_of_an_unknown_zone_is_empty(): void
    {
        $service = $this->service([
            self::API.'/v1beta/dns/zones/*' => Factory::response(['code' => 872, 'desc' => 'Zone not found'], 404),
        ]);

        $this->assertSame([], $service->getDnsRecords('esempio.it'));
    }

    public function test_create_dns_record_adds_a_normalized_record_to_the_zone(): void
    {
        $service = $this->service([
            self::API.'/v1beta/dns/zones/esempio.it' => Factory::response(['code' => 0, 'data' => ['success' => true]]),
        ]);

        $result = $service->createDnsRecord('esempio.it', ['type' => 'txt', 'name' => 'www', 'value' => 'v=spf1 -all']);

        $expected = ['type' => 'TXT', 'name' => 'www', 'value' => 'v=spf1 -all', 'ttl' => 3600];
        $this->assertSame($expected, $result);
        $this->http->assertSent(fn (Request $r): bool => $r->method() === 'PUT'
          && $r['records'] === ['add' => [$expected]]);
    }

    public function test_update_dns_record_removes_the_old_and_adds_the_new_in_one_call(): void
    {
        $service = $this->service([
            self::API.'/v1beta/dns/zones/esempio.it' => Factory::response(['code' => 0, 'data' => ['success' => true]]),
        ]);

        $old = ['type' => 'A', 'name' => '@', 'value' => '192.0.2.1', 'ttl' => 3600];
        $new = ['type' => 'A', 'name' => '@', 'value' => '192.0.2.2', 'ttl' => 600];

        $this->assertSame($new, $service->updateDnsRecord('esempio.it', $old, $new));
        $this->http->assertSent(fn (Request $r): bool => $r->method() === 'PUT'
          && $r['records'] === ['remove' => [$old], 'add' => [$new]]);
    }

    public function test_delete_dns_record_of_a_missing_record_succeeds(): void
    {
        $service = $this->service([
            self::API.'/v1beta/dns/zones/esempio.it' => Factory::response(['code' => 817, 'desc' => 'Record not found'], 400),
        ]);

        $this->assertTrue($service->deleteDnsRecord('esempio.it', ['type' => 'A', 'name' => '@', 'value' => '192.0.2.1']));
    }

    public function test_delete_dns_record_propagates_other_errors(): void
    {
        $service = $this->service([
            self::API.'/v1beta/dns/zones/esempio.it' => Factory::response(['code' => 500, 'desc' => 'Internal error'], 500),
        ]);

        $this->expectException(ProviderRequestException::class);

        $service->deleteDnsRecord('esempio.it', ['type' => 'A', 'name' => '@', 'value' => '192.0.2.1']);
    }

    public function test_transfer_domain_sends_the_auth_code(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/transfer' => Factory::response(['code' => 0, 'data' => ['id' => 88]]),
        ]);

        $result = $service->transferDomain([
            'domain' => 'esempio.it',
            'auth_code' => 'AUTH-1',
            'nameservers' => ['ns1.example.net'],
            'registrant' => ['handle' => 'XX000001-IT'],
        ]);

        $this->assertSame(['op_id' => 88, 'status' => 'pending_transfer'], $result);
        $this->http->assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/v1beta/domains/transfer')
          && $r['auth_code'] === 'AUTH-1' && $r['period'] === 1);
    }

    public function test_update_nameservers(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/88' => Factory::response(['code' => 0, 'data' => []]),
        ]);

        $this->assertSame(['op_id' => 88, 'status' => 'updated'], $service->updateNameservers(88, ['ns1.example.net']));
        $this->http->assertSent(fn (Request $r): bool => $r->method() === 'PUT'
          && $r['name_servers'] === [['name' => 'ns1.example.net']]);
    }

    public function test_get_domain_status(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/88' => Factory::response(['code' => 0, 'data' => ['status' => 'REQ']]),
        ]);

        $this->assertSame(['op_id' => 88, 'status' => 'REQ'], $service->getDomainStatus(88));
    }

    public function test_cancel_transfer(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/transfers/88' => Factory::response(['code' => 0, 'data' => []]),
        ]);

        $this->assertSame(['op_id' => 88, 'status' => 'cancelled'], $service->cancelTransfer(88));
    }

    public function test_cancel_transfer_of_a_transfer_already_closed_is_idempotent(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/transfers/88' => Factory::response(['code' => 404, 'desc' => 'Transfer not found'], 404),
        ]);

        $this->assertSame(['op_id' => 88, 'status' => 'cancelled'], $service->cancelTransfer(88));
    }

    public function test_cancel_transfer_propagates_other_errors(): void
    {
        $service = $this->service([
            self::API.'/v1beta/domains/transfers/88' => Factory::response(['code' => 500, 'desc' => 'Internal error'], 500),
        ]);

        $this->expectException(ProviderRequestException::class);

        $service->cancelTransfer(88);
    }
}
