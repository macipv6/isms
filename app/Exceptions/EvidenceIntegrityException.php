<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class EvidenceIntegrityException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Der Nachweis konnte nicht sicher bereitgestellt werden.', 0, $previous);
    }
}
