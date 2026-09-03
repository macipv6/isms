<?php

namespace App\Exceptions;

use RuntimeException;

class EvidenceIntegrityException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Der Nachweis konnte nicht sicher bereitgestellt werden.');
    }
}
