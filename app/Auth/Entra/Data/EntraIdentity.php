<?php

namespace App\Auth\Entra\Data;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final readonly class EntraIdentity
{
    public function __construct(
        public string $tenantId,
        public string $objectId,
        public string $subject,
        public string $name,
        public string $email,
        public string $issuer,
    ) {
        if (! Uuid::isValid($tenantId)) {
            throw new InvalidArgumentException('Entra tenant ID must be a valid UUID.');
        }

        if (! Uuid::isValid($objectId)) {
            throw new InvalidArgumentException('Entra object ID must be a valid UUID.');
        }

        if (trim($subject) === '') {
            throw new InvalidArgumentException('Entra subject must not be empty.');
        }

        if (trim($issuer) === '') {
            throw new InvalidArgumentException('Entra issuer must not be empty.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Entra email must be valid.');
        }
    }
}
