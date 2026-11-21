# hosterix/providers

[![Tests](https://github.com/landols/laravel-20i-openprovider/actions/workflows/tests.yml/badge.svg)](https://github.com/landols/laravel-20i-openprovider/actions/workflows/tests.yml)

PHP clients for the APIs of two providers used by a hosting reseller:

- **[20i](https://www.20i.com)**, reseller hosting: packages, add-on domains, mailboxes,
  WordPress installation, single sign-on to the StackCP control panel, SSL certificates;
- **[OpenProvider](https://www.openprovider.com)**, domain registrar: availability, registration,
  renewal and transfer of domains, nameservers, DNS records.

Built for Laravel 11, 12 and 13 applications on PHP 8.4 or later.

## Architecture

For each provider the library offers one contract and three interchangeable implementations:

| | Contract | Real | Mock | Hybrid |
|---|---|---|---|---|
| 20i | `TwentiClientInterface` | `TwentiService` | `TwentiMockService` | `TwentiHybridService` |
| OpenProvider | `OpenproviderClientInterface` | `OpenproviderService` | `OpenproviderMockService` | `OpenproviderHybridService` |

- **Real** calls the provider's API.
- **Mock** simulates the provider in memory, with no network. Used in tests and for development
  without credentials.
- **Hybrid** reads from the real API and simulates writes. Used in staging, to work on real data
  without registering domains or creating mailboxes by mistake. Exceptions to this rule are
  documented in the classes.

The application depends only on the contract; the service container binding decides which
implementation it gets.

Every method returns arrays with a fixed shape, documented in the contract
(for example `array{domain: string, available: bool, status: string}`), so application code
does not depend on the provider's response format.

## Installation

The package is not published on Packagist: declare it as a repository in the application's
`composer.json`.

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/landols/laravel-20i-openprovider" }],
    "require": { "hosterix/providers": "dev-main" }
}
```

## Configuration

```php
// config/services.php
'twenti' => [
    'api_key' => env('TWENTI_API_KEY'),
    'oauth_client_key' => env('TWENTI_OAUTH_CLIENT_KEY'), // only for Web Hosting endpoints
    'package_type_id' => env('TWENTI_PACKAGE_TYPE_ID'),
    'mock' => env('TWENTI_MOCK', true),
],

// config/openprovider.php
return [
    'api_url' => env('OPENPROVIDER_API_URL', 'https://api.openprovider.eu'),
    'username' => env('OPENPROVIDER_USERNAME'),
    'password' => env('OPENPROVIDER_PASSWORD'),
    'mock' => env('OPENPROVIDER_MOCK', true),
];
```

Binding in a service provider:

```php
use Hosterix\Providers\Contracts\TwentiClientInterface;
use Hosterix\Providers\Services\Mock\TwentiMockService;
use Hosterix\Providers\Services\TwentiService;

$this->app->singleton(TwentiClientInterface::class, fn ($app) => config('services.twenti.mock')
    ? new TwentiMockService
    : $app->make(TwentiService::class));
```

## Usage

```php
use Hosterix\Providers\Contracts\OpenproviderClientInterface;

final class DomainRegistration
{
    public function __construct(private readonly OpenproviderClientInterface $registrar) {}

    public function register(string $domain, string $ownerHandle): int
    {
        if (! $this->registrar->checkAvailability($domain)['available']) {
            throw new DomainNotAvailable($domain);
        }

        return $this->registrar->registerDomain([
            'domain' => $domain,
            'years' => 1,
            'nameservers' => ['ns1.example.net', 'ns2.example.net'],
            'registrant' => ['handle' => $ownerHandle],
        ])['op_id'];
    }
}
```

## Provider notes

**20i: two kinds of authentication.** Reseller endpoints (`/reseller/*`, `GET /package`) expect
the token `base64(API_KEY)`; Web Hosting endpoints (`/package/{id}/web/*`, `/email/*`) expect
`base64(API_KEY+OAUTH_CLIENT_KEY)`. `TwentiService` picks the right token for each call;
without `oauth_client_key` it falls back to the API key alone. Reads that need two endpoints
run in parallel.

**OpenProvider: login on first use.** The service obtains its token on the first request and
reuses it; constructing it opens no connection. Deleting a DNS record that does not exist and
cancelling a transfer that is already closed are treated as successful, so operations can be
safely retried.

## Errors

Every exception thrown by the library extends `Hosterix\Providers\Exceptions\ProviderException`
(itself a `RuntimeException`):

| Exception | When |
|---|---|
| `ProviderConfigurationException` | credentials missing from the configuration |
| `ProviderAuthenticationException` | the provider rejects the credentials |
| `ProviderRequestException` | the provider returns an error or an invalid response; `httpStatus` holds the HTTP status, when there is one |
| `UnsupportedOperationException` | the provider's API does not offer the operation (for example deleting a 20i package) |

Exception messages are in Italian.

## Tests

```bash
composer install
composer test   # PHPUnit: real clients against faked HTTP, mocks and hybrids
composer lint   # Laravel Pint
```

CI runs both on PHP 8.4 and 8.5.

## License

Published for reference only. All rights reserved: no licence to reuse the code is granted.
The repository does not accept external contributions.

Mario Landolfo · [github.com/landols](https://github.com/landols)
