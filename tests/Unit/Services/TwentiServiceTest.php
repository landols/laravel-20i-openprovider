<?php

declare(strict_types=1);

namespace Hosterix\Providers\Tests\Unit\Services;

use Hosterix\Providers\Contracts\TwentiClientInterface;
use Hosterix\Providers\Exceptions\ProviderConfigurationException;
use Hosterix\Providers\Exceptions\ProviderRequestException;
use Hosterix\Providers\Exceptions\UnsupportedOperationException;
use Hosterix\Providers\Services\TwentiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Orchestra\Testbench\TestCase;

class TwentiServiceTest extends TestCase
{
    private const API = 'https://api.20i.com';

    private Factory $http;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twenti.api_key' => 'key',
            'services.twenti.oauth_client_key' => 'oauth',
            'services.twenti.package_type_id' => 1234,
        ]);

        $this->http = new Factory;
    }

    /**
     * @param  array<string, mixed>  $routes
     */
    private function service(array $routes = []): TwentiService
    {
        $this->http->fake($routes);

        return new TwentiService($this->http);
    }

    private function resellerToken(): string
    {
        return 'Bearer '.base64_encode('key');
    }

    private function hostingToken(): string
    {
        return 'Bearer '.base64_encode('key+oauth');
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(TwentiClientInterface::class, $this->service());
    }

    public function test_missing_api_key_raises_a_configuration_exception(): void
    {
        config(['services.twenti.api_key' => '']);
        $service = $this->service();

        $this->expectException(ProviderConfigurationException::class);
        $this->expectExceptionMessage('20i API key non configurata (TWENTI_API_KEY).');

        $service->getPackages();
    }

    public function test_reseller_endpoints_use_the_api_key_alone(): void
    {
        $service = $this->service([self::API.'/package' => Factory::response([])]);

        $service->getPackages();

        $this->http->assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', $this->resellerToken()));
    }

    public function test_hosting_endpoints_combine_api_key_and_oauth_key(): void
    {
        $service = $this->service([self::API.'/package/p1/email/mailboxes' => Factory::response([])]);

        $service->getMailboxes('p1');

        $this->http->assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', $this->hostingToken()));
    }

    public function test_hosting_endpoints_fall_back_to_the_api_key_without_oauth_key(): void
    {
        config(['services.twenti.oauth_client_key' => '']);
        $service = $this->service([self::API.'/package/p1/email/mailboxes' => Factory::response([])]);

        $service->getMailboxes('p1');

        $this->http->assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', $this->resellerToken()));
    }

    public function test_get_package_types_formats_the_limits(): void
    {
        $service = $this->service([self::API.'/reseller/*/packageTypes' => Factory::response([
            ['id' => '7', 'label' => 'Linux', 'limits' => ['webspace' => 'INF', 'bandwidth' => 0, 'mailboxes' => 10, 'freeSsl' => 1]],
        ])]);

        $this->assertSame([[
            'id' => 7,
            'label' => 'Linux',
            'disk' => 'Illimitato',
            'bandwidth' => 'Non incluso',
            'mailboxes' => '10',
            'databases' => 'Illimitato',
            'ssl' => true,
            'ssh' => false,
            'backups' => false,
        ]], $service->getPackageTypes());
    }

    public function test_get_packages_maps_the_status(): void
    {
        $service = $this->service([self::API.'/package' => Factory::response([
            ['id' => 1, 'name' => 'esempio.it', 'enabled' => true],
            ['id' => 2, 'name' => 'sospeso.it', 'enabled' => false],
        ])]);

        $packages = $service->getPackages();

        $this->assertSame(['1', 'active'], [$packages[0]['id'], $packages[0]['status']]);
        $this->assertSame(['2', 'suspended'], [$packages[1]['id'], $packages[1]['status']]);
    }

    public function test_get_package_merges_detail_usage_and_php_version(): void
    {
        $service = $this->service([
            self::API.'/package/p1' => Factory::response([
                'id' => 'p1', 'names' => ['esempio.it', 'www.esempio.it'], 'enabled' => true,
                'limits' => ['webspace' => 1024, 'bandwidth' => 'INF'],
            ]),
            self::API.'/package/p1/web/usage' => Factory::response(['DiskMb' => 1.5, 'Bandwidth' => ['MbIn' => 1, 'MbOut' => 2]]),
            self::API.'/package/p1/web/phpVersion' => Factory::response('"php83"'),
        ]);

        $this->assertSame([
            'id' => 'p1',
            'name' => 'esempio.it',
            'label' => 'esempio.it',
            'disk_used' => 1572864,
            'disk_limit' => 1073741824,
            'bandwidth_used' => 3145728,
            'bandwidth_limit' => 0,
            'php_version' => '8.3',
            'domains' => ['esempio.it', 'www.esempio.it'],
            'status' => 'active',
        ], $service->getPackage('p1'));
    }

    public function test_a_failed_parallel_call_raises_a_request_exception(): void
    {
        $service = $this->service([
            self::API.'/package/p1' => Factory::response(['id' => 'p1']),
            self::API.'/package/p1/web/usage' => fn () => throw new ConnectionException('timeout'),
        ]);

        $this->expectException(ProviderRequestException::class);
        $this->expectExceptionMessage('20i parallel call failed for /package/p1/web/usage: timeout');

        $service->getPackage('p1');
    }

    public function test_get_package_domains_joins_the_document_roots(): void
    {
        $service = $this->service([
            self::API.'/package/p1/web/names' => Factory::response(['esempio.it', 'altro.it']),
            self::API.'/package/p1/domain' => Factory::response([['name' => 'altro.it', 'document_root' => '/altro']]),
        ]);

        $this->assertSame([
            ['name' => 'esempio.it', 'document_root' => ''],
            ['name' => 'altro.it', 'document_root' => '/altro'],
        ], $service->getPackageDomains('p1'));
    }

    public function test_create_package_uses_the_default_package_type(): void
    {
        $service = $this->service([self::API.'/reseller/*/addWeb' => Factory::response(['result' => 999])]);

        $this->assertSame(['id' => '999', 'status' => 'provisioning'], $service->createPackage(['domain' => 'esempio.it']));
        $this->http->assertSent(fn (Request $r): bool => $r['domain_name'] === 'esempio.it' && $r['type'] === 1234);
    }

    public function test_create_package_without_an_id_in_the_response_fails(): void
    {
        $service = $this->service([self::API.'/reseller/*/addWeb' => Factory::response(['error' => 'x'])]);

        $this->expectException(ProviderRequestException::class);

        $service->createPackage(['domain' => 'esempio.it']);
    }

    public function test_delete_package_is_not_supported(): void
    {
        $this->expectException(UnsupportedOperationException::class);

        $this->service()->deletePackage('p1');
    }

    public function test_an_http_error_is_propagated(): void
    {
        $service = $this->service([self::API.'/package' => Factory::response(['error' => 'unauthorized'], 401)]);

        $this->expectException(RequestException::class);

        $service->getPackages();
    }

    public function test_add_domain_to_package_defaults_the_document_root(): void
    {
        $service = $this->service([self::API.'/package/p1/web/addName' => Factory::response([])]);

        $this->assertSame(
            ['package_id' => 'p1', 'domain' => 'nuovo.it', 'document_root' => '/domains/nuovo.it/public_html'],
            $service->addDomainToPackage('p1', 'nuovo.it'),
        );
    }

    public function test_create_mailbox_converts_the_quota_to_bytes(): void
    {
        $service = $this->service([self::API.'/package/p1/email/mailboxes' => Factory::response(['id' => 5])]);

        $result = $service->createMailbox('p1', ['local_part' => 'info', 'domain' => 'esempio.it', 'password' => 'pw', 'quota_mb' => 2]);

        $this->assertSame(['id' => '5', 'address' => 'info@esempio.it', 'status' => 'active'], $result);
        $this->http->assertSent(fn (Request $r): bool => $r['localPart'] === 'info' && $r['quota'] === 2097152);
    }

    public function test_delete_and_update_mailbox(): void
    {
        $service = $this->service([self::API.'/package/p1/email/mailboxes/5' => Factory::response([])]);

        $this->assertTrue($service->deleteMailbox('p1', '5'));
        $this->assertTrue($service->updateMailboxPassword('p1', '5', 'nuova'));
        $this->http->assertSent(fn (Request $r): bool => $r->method() === 'DELETE');
        $this->http->assertSent(fn (Request $r): bool => $r->method() === 'PUT' && $r['password'] === 'nuova');
    }

    public function test_installed_applications_get_an_admin_url(): void
    {
        $service = $this->service([self::API.'/package/p1/web/installedApplications' => Factory::response([
            ['id' => 3, 'name' => 'wordpress', 'domain' => 'esempio.it', 'path' => '/blog/'],
        ])]);

        $this->assertSame([[
            'id' => '3',
            'name' => 'wordpress',
            'domain' => 'esempio.it',
            'path' => '/blog/',
            'status' => 'installed',
            'admin_url' => 'https://esempio.it/blog/wp-admin',
        ]], $service->getInstalledApplications('p1'));
    }

    public function test_install_application_sends_the_admin_credentials(): void
    {
        $service = $this->service([self::API.'/package/p1/web/installApplication' => Factory::response(['id' => 9])]);

        $result = $service->installApplication('p1', [
            'domain' => 'esempio.it', 'admin_user' => 'admin', 'admin_email' => 'admin@esempio.it',
            'admin_password' => 'pw', 'site_title' => 'Esempio',
        ]);

        $this->assertSame(['id' => '9', 'status' => 'installing', 'admin_url' => 'https://esempio.it/wp-admin'], $result);
        $this->http->assertSent(fn (Request $r): bool => $r['name'] === 'wordpress'
          && $r['params']['adminUsername'] === 'admin' && $r['params']['siteName'] === 'Esempio');
    }

    public function test_sso_url_creates_a_stack_user_when_none_exists(): void
    {
        $service = $this->service([
            self::API.'/package/p1/stackUserList' => $this->http->sequence()
                ->push([])
                ->push(['reference' => 'stack-user:1']),
            self::API.'/reseller/*/controlPanelTokenForUser' => Factory::response(['token' => 'cp-token']),
            self::API.'/package/p1' => Factory::response(['names' => ['esempio.it']]),
            self::API.'/reseller/*/singleSignOn' => Factory::response(['url' => 'https://stackcp.example/sso']),
        ]);

        $this->assertSame('https://stackcp.example/sso', $service->getSsoUrlForPackage('p1'));
        $this->http->assertSent(fn (Request $r): bool => str_ends_with($r->url(), 'controlPanelTokenForUser')
          && $r['stackUser'] === 'stack-user:1');
        $this->http->assertSent(fn (Request $r): bool => str_ends_with($r->url(), 'singleSignOn')
          && $r['token'] === 'cp-token' && $r['package'] === 'esempio.it');
    }

    public function test_sso_url_with_an_empty_token_fails(): void
    {
        $service = $this->service([
            self::API.'/package/p1/stackUserList' => Factory::response([['reference' => 'stack-user:1']]),
            self::API.'/reseller/*/controlPanelTokenForUser' => Factory::response([]),
        ]);

        $this->expectException(ProviderRequestException::class);

        $service->getSsoUrlForPackage('p1');
    }

    public function test_ssl_status_is_normalized(): void
    {
        $service = $this->service([self::API.'/package/p1/web/ssl' => Factory::response(['state' => 'Issuing', 'domain' => 'esempio.it'])]);

        $this->assertSame(
            ['status' => 'pending', 'expires_at' => null, 'issuer' => "Let's Encrypt", 'domain' => 'esempio.it'],
            $service->getSslStatus('p1'),
        );
    }

    public function test_request_ssl_reissue(): void
    {
        $service = $this->service([self::API.'/package/p1/web/ssl/reissue' => Factory::response([])]);

        $this->assertTrue($service->requestSslReissue('p1'));
    }
}
