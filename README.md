# hosterix/providers

[![Tests](https://github.com/landols/laravel-20i-openprovider/actions/workflows/tests.yml/badge.svg)](https://github.com/landols/laravel-20i-openprovider/actions/workflows/tests.yml)

Client PHP per le API di due provider usati da un rivenditore di hosting:

- **[20i](https://www.20i.com)**, hosting in reseller: pacchetti, domini aggiuntivi, caselle email,
  installazione di WordPress, accesso diretto al pannello StackCP, certificati SSL;
- **[OpenProvider](https://www.openprovider.com)**, registrar: disponibilità, registrazione,
  rinnovo e trasferimento dei domini, nameserver, record DNS.

Pensato per applicazioni Laravel 11, 12 e 13 su PHP 8.4 o successivo.

## Architettura

Per ogni provider la libreria offre un contratto e tre implementazioni intercambiabili:

| | Contratto | Reale | Mock | Ibrida |
|---|---|---|---|---|
| 20i | `TwentiClientInterface` | `TwentiService` | `TwentiMockService` | `TwentiHybridService` |
| OpenProvider | `OpenproviderClientInterface` | `OpenproviderService` | `OpenproviderMockService` | `OpenproviderHybridService` |

- **Reale**: chiama l'API del provider.
- **Mock**: simula il provider in memoria, senza rete. Serve ai test e allo sviluppo senza credenziali.
- **Ibrida**: legge dall'API reale e simula le scritture. Serve in staging, per lavorare su dati veri
  senza registrare domini o creare caselle per errore. Le eccezioni alla regola sono documentate
  nelle classi.

L'applicazione dipende solo dal contratto; quale implementazione usare lo decide il binding nel
service container.

Ogni metodo restituisce array con una forma fissa, documentata nel contratto
(per esempio `array{domain: string, available: bool, status: string}`), così il codice
dell'applicazione non dipende dal formato di risposta del provider.

## Installazione

Il pacchetto non è pubblicato su Packagist: si dichiara come repository nel `composer.json`
dell'applicazione.

```json
{
    "repositories": [{ "type": "vcs", "url": "https://github.com/landols/laravel-20i-openprovider" }],
    "require": { "hosterix/providers": "dev-main" }
}
```

## Configurazione

```php
// config/services.php
'twenti' => [
    'api_key' => env('TWENTI_API_KEY'),
    'oauth_client_key' => env('TWENTI_OAUTH_CLIENT_KEY'), // solo per gli endpoint Web Hosting
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

Binding in un service provider:

```php
use Hosterix\Providers\Contracts\TwentiClientInterface;
use Hosterix\Providers\Services\Mock\TwentiMockService;
use Hosterix\Providers\Services\TwentiService;

$this->app->singleton(TwentiClientInterface::class, fn ($app) => config('services.twenti.mock')
    ? new TwentiMockService
    : $app->make(TwentiService::class));
```

## Uso

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

## Note sui provider

**20i: due autenticazioni.** Gli endpoint Reseller (`/reseller/*`, `GET /package`) vogliono
il token `base64(API_KEY)`; quelli Web Hosting (`/package/{id}/web/*`, `/email/*`)
`base64(API_KEY+OAUTH_CLIENT_KEY)`. `TwentiService` sceglie il token giusto per ogni chiamata;
senza `oauth_client_key` usa la sola API key. Le letture che richiedono due endpoint
partono in parallelo.

**OpenProvider: login al primo uso.** Il servizio ottiene il token alla prima richiesta e lo
riusa; costruirlo non apre connessioni. La cancellazione di un record DNS che non esiste
e l'annullamento di un trasferimento già chiuso sono trattati come riusciti, così le
operazioni si possono ripetere senza errori.

## Errori

Tutte le eccezioni della libreria estendono `Hosterix\Providers\Exceptions\ProviderException`
(a sua volta `RuntimeException`):

| Eccezione | Quando |
|---|---|
| `ProviderConfigurationException` | credenziali mancanti nella configurazione |
| `ProviderAuthenticationException` | il provider rifiuta le credenziali |
| `ProviderRequestException` | il provider risponde con un errore o una risposta non valida; `httpStatus` riporta lo status HTTP, quando c'è |
| `UnsupportedOperationException` | l'API del provider non offre l'operazione (per esempio la cancellazione di un pacchetto 20i) |

## Test

```bash
composer install
composer test   # PHPUnit: client reali con HTTP simulato, mock e ibridi
composer lint   # Laravel Pint
```

La CI li esegue su PHP 8.4 e 8.5.

## Licenza

Codice pubblicato in sola consultazione. Tutti i diritti riservati: non è concessa alcuna
licenza di riutilizzo. Il repository non accetta contributi esterni.

Mario Landolfo · [github.com/landols](https://github.com/landols)
