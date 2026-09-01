<?php

namespace App\Auth\Entra\Exceptions;

use RuntimeException;
use Throwable;

class EntraAuthenticationException extends RuntimeException
{
    /**
     * @param  array<string, string|null>  $context
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
