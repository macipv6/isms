<?php

namespace App\Auth\Entra\Contracts;

use App\Auth\Entra\Data\EntraIdentity;
use Symfony\Component\HttpFoundation\RedirectResponse;

interface EntraIdentityProvider
{
    public function redirect(): RedirectResponse;

    public function identityFromCallback(): EntraIdentity;
}
