<?php

declare(strict_types=1);

namespace Hosterix\Providers\Exceptions;

use RuntimeException;

/**
 * Base di ogni errore sollevato dai client dei provider.
 *
 * Estende RuntimeException, quindi il codice che intercetta Exception continua a funzionare.
 */
class ProviderException extends RuntimeException {}
