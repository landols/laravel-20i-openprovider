<?php

declare(strict_types=1);

namespace Hosterix\Providers\Exceptions;

use Throwable;

/**
 * Il provider ha risposto con un errore o con una risposta non valida.
 */
class ProviderRequestException extends ProviderException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
