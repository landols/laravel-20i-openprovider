<?php

declare(strict_types=1);

namespace Hosterix\Providers\Tests\Unit\Services;

use Hosterix\Providers\Contracts\TwentiClientInterface;
use Hosterix\Providers\Services\Mock\TwentiMockService;
use Hosterix\Providers\Services\TwentiHybridService;
use Hosterix\Providers\Services\TwentiService;
use PHPUnit\Framework\TestCase;

class TwentiHybridServiceTest extends TestCase
{
    private TwentiHybridService $hybrid;

    private TwentiService $real;

    private TwentiMockService $mock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->real = $this->createStub(TwentiService::class);
        $this->mock = new TwentiMockService;

        $this->hybrid = new TwentiHybridService($this->real, $this->mock);
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(TwentiClientInterface::class, $this->hybrid);
    }

    // -- Read delegates to real -----------------------------------------------

    public function test_get_package_types_delegates_to_real(): void
    {
        $expected = [['id' => 1, 'label' => 'Test']];
        $this->real->method('getPackageTypes')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->getPackageTypes());
    }

    public function test_get_packages_delegates_to_real(): void
    {
        $expected = [['id' => 'pkg-1', 'name' => 'test.it']];
        $this->real->method('getPackages')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->getPackages());
    }

    public function test_get_package_delegates_to_real(): void
    {
        $expected = ['id' => 'pkg-1', 'name' => 'test.it', 'status' => 'active'];
        $this->real->method('getPackage')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->getPackage('pkg-1'));
    }

    public function test_get_package_domains_delegates_to_real(): void
    {
        $expected = [['name' => 'test.it', 'document_root' => '/']];
        $this->real->method('getPackageDomains')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->getPackageDomains('pkg-1'));
    }

    // -- Write ----------------------------------------------------------------

    public function test_create_package_delegates_to_real(): void
    {
        $expected = ['id' => 'pkg-new', 'status' => 'provisioning'];
        $this->real->method('createPackage')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->createPackage([
            'domain' => 'new.it',
            'label' => 'New',
            'stack_user' => 'test',
        ]));
    }

    public function test_delete_package_delegates_to_mock(): void
    {
        // deletePackage usa sempre il mock (20i non supporta delete via API)
        $this->assertTrue($this->hybrid->deletePackage('pkg-001'));
    }

    // -- addDomainToPackage: write → mock -------------------------------------

    public function test_add_domain_to_package_delegates_to_mock(): void
    {
        $result = $this->hybrid->addDomainToPackage('pkg-001', 'nuovo.it');

        $this->assertSame('pkg-001', $result['package_id']);
        $this->assertSame('nuovo.it', $result['domain']);
    }

    // -- getMailboxes: read → real --------------------------------------------

    public function test_get_mailboxes_delegates_to_real(): void
    {
        $expected = [['id' => 'mbx-1', 'address' => 'info@test.it', 'quota_used' => 0, 'quota_limit' => 0, 'status' => 'active']];
        $this->real->method('getMailboxes')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->getMailboxes('pkg-001'));
    }

    // -- createMailbox: write → mock ------------------------------------------

    public function test_create_mailbox_delegates_to_mock(): void
    {
        $result = $this->hybrid->createMailbox('pkg-002', [
            'local_part' => 'info',
            'domain' => 'cliente-demo.net',
            'password' => 'Secure1!abc',
            'quota_mb' => 1000,
        ]);

        $this->assertSame('active', $result['status']);
        $this->assertStringContainsString('info@cliente-demo.net', $result['address']);
    }

    // -- deleteMailbox: write → mock ------------------------------------------

    public function test_delete_mailbox_delegates_to_mock(): void
    {
        $this->assertTrue($this->hybrid->deleteMailbox('pkg-001', 'mbx-aaa'));
    }

    // -- updateMailboxPassword: write → mock ----------------------------------

    public function test_update_mailbox_password_delegates_to_mock(): void
    {
        $this->assertTrue($this->hybrid->updateMailboxPassword('pkg-001', 'mbx-aaa', 'NewPass1!abc'));
    }

    // -- App installer: read → real, write → mock ----------------------------

    public function test_get_installed_applications_delegates_to_real(): void
    {
        $expected = [['id' => 'app-1', 'name' => 'wordpress', 'domain' => 'x.it', 'path' => '/', 'status' => 'installed', 'admin_url' => 'https://x.it/wp-admin']];
        $this->real->method('getInstalledApplications')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->getInstalledApplications('pkg-001'));
    }

    public function test_install_application_delegates_to_mock(): void
    {
        $result = $this->hybrid->installApplication('pkg-001', [
            'app' => 'wordpress',
            'domain' => 'demo.it',
            'admin_user' => 'admin',
            'admin_email' => 'admin@demo.it',
            'admin_password' => 'Pw123!abc',
            'site_title' => 'Demo',
        ]);

        $this->assertSame('installed', $result['status']);
        $this->assertStringContainsString('demo.it', $result['admin_url']);
    }

    // -- SSO URL: delegates to mock (URL fittizio in dev/staging) -----------

    public function test_sso_url_delegates_to_mock(): void
    {
        $url = $this->hybrid->getSsoUrlForPackage('pkg-001');

        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('pkg-001', $url);
        $this->assertStringContainsString('token=', $url);
    }

    // -- SSL: read → real, write (reissue) → mock ----------------------------

    public function test_ssl_status_delegates_to_real(): void
    {
        $expected = ['status' => 'active', 'expires_at' => '2026-12-01', 'issuer' => "Let's Encrypt", 'domain' => 'x.it'];
        $this->real->method('getSslStatus')->willReturn($expected);

        $this->assertSame($expected, $this->hybrid->getSslStatus('pkg-001'));
    }

    public function test_ssl_reissue_delegates_to_mock(): void
    {
        $this->assertTrue($this->hybrid->requestSslReissue('pkg-001'));
    }
}
