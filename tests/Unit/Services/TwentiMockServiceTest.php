<?php

declare(strict_types=1);

namespace Hosterix\Providers\Tests\Unit\Services;

use Hosterix\Providers\Contracts\TwentiClientInterface;
use Hosterix\Providers\Services\Mock\TwentiMockService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TwentiMockServiceTest extends TestCase
{
    private TwentiMockService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TwentiMockService;
    }

    public function test_implements_interface(): void
    {
        $this->assertInstanceOf(TwentiClientInterface::class, $this->service);
    }

    // -- getPackageTypes ------------------------------------------------------

    public function test_get_package_types_returns_list(): void
    {
        $types = $this->service->getPackageTypes();

        $this->assertNotEmpty($types);

        foreach ($types as $type) {
            $this->assertArrayHasKey('id', $type);
            $this->assertArrayHasKey('label', $type);
            $this->assertArrayHasKey('disk', $type);
            $this->assertArrayHasKey('bandwidth', $type);
            $this->assertArrayHasKey('ssl', $type);
            $this->assertIsBool($type['ssl']);
        }
    }

    // -- getPackages ----------------------------------------------------------

    public function test_get_packages_returns_list_without_php_and_domains(): void
    {
        $packages = $this->service->getPackages();

        $this->assertNotEmpty($packages);

        foreach ($packages as $pkg) {
            $this->assertArrayHasKey('id', $pkg);
            $this->assertArrayHasKey('name', $pkg);
            $this->assertArrayHasKey('status', $pkg);
            $this->assertArrayHasKey('disk_used', $pkg);
            $this->assertArrayNotHasKey('php_version', $pkg);
            $this->assertArrayNotHasKey('domains', $pkg);
        }
    }

    // -- getPackage -----------------------------------------------------------

    public function test_get_package_returns_details(): void
    {
        $package = $this->service->getPackage('pkg-001');

        $this->assertSame('pkg-001', $package['id']);
        $this->assertSame('hosterix.it', $package['name']);
        $this->assertArrayHasKey('php_version', $package);
        $this->assertArrayHasKey('domains', $package);
        $this->assertIsArray($package['domains']);
    }

    public function test_get_package_throws_on_invalid_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->getPackage('nonexistent');
    }

    // -- getPackageDomains ----------------------------------------------------

    public function test_get_package_domains_returns_domains_with_root(): void
    {
        $domains = $this->service->getPackageDomains('pkg-002');

        $this->assertCount(3, $domains);

        foreach ($domains as $domain) {
            $this->assertArrayHasKey('name', $domain);
            $this->assertArrayHasKey('document_root', $domain);
            $this->assertStringContainsString('/public_html', $domain['document_root']);
        }
    }

    // -- createPackage --------------------------------------------------------

    public function test_create_package_returns_provisioning(): void
    {
        $result = $this->service->createPackage([
            'label' => 'Test',
            'domain' => 'test-create.it',
            'stack_user' => 'test',
        ]);

        $this->assertSame('provisioning', $result['status']);
        $this->assertStringStartsWith('pkg-', $result['id']);
    }

    public function test_create_package_generates_deterministic_id(): void
    {
        $result1 = $this->service->createPackage(['domain' => 'same.it', 'label' => '', 'stack_user' => '']);
        $result2 = $this->service->createPackage(['domain' => 'same.it', 'label' => '', 'stack_user' => '']);

        $this->assertSame($result1['id'], $result2['id']);
    }

    // -- deletePackage --------------------------------------------------------

    public function test_delete_package_returns_true(): void
    {
        $this->assertTrue($this->service->deletePackage('pkg-001'));
    }

    // -- addDomainToPackage ---------------------------------------------------

    public function test_add_domain_to_package_returns_expected_shape(): void
    {
        $result = $this->service->addDomainToPackage('pkg-001', 'nuovo-sito.it');

        $this->assertSame('pkg-001', $result['package_id']);
        $this->assertSame('nuovo-sito.it', $result['domain']);
        $this->assertStringContainsString('public_html', $result['document_root']);
    }

    // -- getMailboxes ---------------------------------------------------------

    public function test_get_mailboxes_seeds_pkg001_with_two_mailboxes(): void
    {
        $mailboxes = $this->service->getMailboxes('pkg-001');

        $this->assertCount(2, $mailboxes);
        $addresses = array_column($mailboxes, 'address');
        $this->assertContains('info@hosterix.it', $addresses);
        $this->assertContains('noreply@hosterix.it', $addresses);
    }

    public function test_get_mailboxes_returns_empty_for_unknown_package(): void
    {
        $this->assertSame([], $this->service->getMailboxes('pkg-unknown'));
    }

    // -- createMailbox --------------------------------------------------------

    public function test_create_mailbox_adds_to_package(): void
    {
        $result = $this->service->createMailbox('pkg-002', [
            'local_part' => 'info',
            'domain' => 'cliente-demo.net',
            'password' => 'SecurePass1!',
            'quota_mb' => 500,
        ]);

        $this->assertStringStartsWith('mbx-', $result['id']);
        $this->assertSame('info@cliente-demo.net', $result['address']);
        $this->assertSame('active', $result['status']);

        $mailboxes = $this->service->getMailboxes('pkg-002');
        $this->assertCount(1, $mailboxes);
    }

    public function test_create_mailbox_generates_deterministic_id(): void
    {
        $data = ['local_part' => 'test', 'domain' => 'example.it', 'password' => 'X', 'quota_mb' => 1000];
        $r1 = $this->service->createMailbox('pkg-002', $data);

        $service2 = new TwentiMockService;
        $r2 = $service2->createMailbox('pkg-002', $data);

        $this->assertSame($r1['id'], $r2['id']);
    }

    // -- deleteMailbox --------------------------------------------------------

    public function test_delete_mailbox_removes_from_package(): void
    {
        $mailboxes = $this->service->getMailboxes('pkg-001');
        $firstId = $mailboxes[0]['id'];

        $result = $this->service->deleteMailbox('pkg-001', $firstId);

        $this->assertTrue($result);
        $remaining = $this->service->getMailboxes('pkg-001');
        $this->assertCount(1, $remaining);
        $this->assertNotSame($firstId, $remaining[0]['id']);
    }

    // -- updateMailboxPassword ------------------------------------------------

    public function test_update_mailbox_password_returns_true(): void
    {
        $this->assertTrue($this->service->updateMailboxPassword('pkg-001', 'mbx-aaa', 'NewPass123!'));
    }

    // -- installApplication ---------------------------------------------------

    public function test_installed_applications_initially_empty(): void
    {
        $this->assertSame([], $this->service->getInstalledApplications('pkg-001'));
    }

    public function test_install_application_returns_installed_status(): void
    {
        $result = $this->service->installApplication('pkg-001', [
            'app' => 'wordpress',
            'domain' => 'hosterix.it',
            'admin_user' => 'admin',
            'admin_email' => 'admin@hosterix.it',
            'admin_password' => 'Strong#Pass123',
            'site_title' => 'Il mio sito',
        ]);

        $this->assertSame('installed', $result['status']);
        $this->assertStringContainsString('hosterix.it', $result['admin_url']);
        $this->assertStringContainsString('wp-admin', $result['admin_url']);
        $this->assertNotEmpty($result['id']);
    }

    public function test_install_application_appears_in_installed_list(): void
    {
        $this->service->installApplication('pkg-001', [
            'app' => 'wordpress',
            'domain' => 'hosterix.it',
            'admin_user' => 'admin',
            'admin_email' => 'admin@hosterix.it',
            'admin_password' => 'Strong#Pass123',
            'site_title' => 'Test',
        ]);

        $apps = $this->service->getInstalledApplications('pkg-001');

        $this->assertCount(1, $apps);
        $this->assertSame('wordpress', $apps[0]['name']);
        $this->assertSame('hosterix.it', $apps[0]['domain']);
        $this->assertSame('installed', $apps[0]['status']);
    }

    public function test_install_application_isolated_per_package(): void
    {
        $this->service->installApplication('pkg-001', [
            'app' => 'wordpress', 'domain' => 'a.it',
            'admin_user' => 'a', 'admin_email' => 'a@a.it',
            'admin_password' => 'x', 'site_title' => 'A',
        ]);
        $this->service->installApplication('pkg-002', [
            'app' => 'wordpress', 'domain' => 'b.it',
            'admin_user' => 'b', 'admin_email' => 'b@b.it',
            'admin_password' => 'y', 'site_title' => 'B',
        ]);

        $this->assertCount(1, $this->service->getInstalledApplications('pkg-001'));
        $this->assertCount(1, $this->service->getInstalledApplications('pkg-002'));
        $this->assertSame('a.it', $this->service->getInstalledApplications('pkg-001')[0]['domain']);
        $this->assertSame('b.it', $this->service->getInstalledApplications('pkg-002')[0]['domain']);
    }

    // -- getSsoUrlForPackage --------------------------------------------------

    public function test_sso_url_includes_package_id(): void
    {
        $url = $this->service->getSsoUrlForPackage('pkg-001');

        $this->assertStringStartsWith('https://', $url);
        $this->assertStringContainsString('pkg-001', $url);
        $this->assertStringContainsString('token=', $url);
    }

    public function test_sso_url_token_is_unique_per_call(): void
    {
        $a = $this->service->getSsoUrlForPackage('pkg-001');
        usleep(1000); // 1ms — basta a cambiare microtime()
        $b = $this->service->getSsoUrlForPackage('pkg-001');

        $this->assertNotSame($a, $b, 'Ogni chiamata deve generare un token unico');
    }

    // -- SSL status -----------------------------------------------------------

    public function test_ssl_status_default_active(): void
    {
        $ssl = $this->service->getSslStatus('pkg-001');

        $this->assertSame('active', $ssl['status']);
        $this->assertNotNull($ssl['expires_at']);
        $this->assertSame("Let's Encrypt", $ssl['issuer']);
        $this->assertSame('hosterix.it', $ssl['domain']);
    }

    public function test_ssl_reissue_sets_pending(): void
    {
        $ok = $this->service->requestSslReissue('pkg-001');
        $this->assertTrue($ok);

        $ssl = $this->service->getSslStatus('pkg-001');
        $this->assertSame('pending', $ssl['status']);
        $this->assertNull($ssl['expires_at']);
    }

    public function test_ssl_status_unknown_package_returns_default_domain(): void
    {
        $ssl = $this->service->getSslStatus('pkg-non-esistente');

        $this->assertSame('active', $ssl['status']);
        $this->assertSame('esempio.it', $ssl['domain']);
    }
}
