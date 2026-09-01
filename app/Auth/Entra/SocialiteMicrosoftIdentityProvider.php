<?php

namespace App\Auth\Entra;

use App\Auth\Entra\Contracts\EntraIdentityProvider;
use App\Auth\Entra\Data\EntraIdentity;
use App\Auth\Entra\Exceptions\EntraAuthenticationException;
use Illuminate\Support\Facades\Session;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Microsoft\Provider as MicrosoftProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class SocialiteMicrosoftIdentityProvider implements EntraIdentityProvider
{
    public function redirect(): RedirectResponse
    {
        $nonce = bin2hex(random_bytes(32));
        Session::put('entra.oidc_nonce', $nonce);

        /** @var MicrosoftProvider $provider */
        $provider = Socialite::driver('microsoft');

        return $provider
            ->scopes(['openid', 'profile', 'email', 'User.Read'])
            ->with(['nonce' => $nonce])
            ->redirect();
    }

    public function identityFromCallback(): EntraIdentity
    {
        try {
            /** @var MicrosoftProvider $provider */
            $provider = Socialite::driver('microsoft');
            $socialiteUser = $provider->user();
            $claims = $provider->getClaims();

            if ($claims === null) {
                throw new EntraAuthenticationException('identity_provider_error', 'Microsoft did not return validated ID-token claims.');
            }

            $tenantId = (string) ($claims->tid ?? '');
            $configuredTenant = (string) config('services.microsoft.tenant');

            if ($tenantId === '' || $configuredTenant === '' || ! hash_equals(strtolower($configuredTenant), strtolower($tenantId))) {
                throw new EntraAuthenticationException('tenant_mismatch', 'Microsoft Entra tenant does not match the configured tenant.', ['entra_tenant_id' => $tenantId]);
            }

            $objectId = (string) ($claims->oid ?? '');
            $graphObjectId = (string) $socialiteUser->getId();

            if ($objectId === '' || $graphObjectId === '' || ! hash_equals(strtolower($objectId), strtolower($graphObjectId))) {
                throw new EntraAuthenticationException('identity_provider_error', 'ID-token object ID does not match Microsoft Graph identity.');
            }

            $expectedNonce = Session::pull('entra.oidc_nonce');
            $returnedNonce = (string) ($claims->nonce ?? '');

            if (! is_string($expectedNonce) || $expectedNonce === '' || $returnedNonce === '' || ! hash_equals($expectedNonce, $returnedNonce)) {
                throw new EntraAuthenticationException('identity_provider_error', 'OIDC nonce validation failed.');
            }

            $issuer = (string) ($claims->iss ?? '');
            $subject = (string) ($claims->sub ?? '');
            $email = $this->firstUsableEmail([
                $claims->email ?? null,
                $claims->preferred_username ?? null,
                $socialiteUser->getEmail(),
                $socialiteUser->user['userPrincipalName'] ?? null,
                $socialiteUser->user['mail'] ?? null,
            ]);

            if ($issuer === '' || $subject === '' || $email === null) {
                throw new EntraAuthenticationException('identity_provider_error', 'Required Microsoft identity claims are missing.');
            }

            return new EntraIdentity(
                tenantId: $tenantId,
                objectId: $objectId,
                subject: $subject,
                name: trim((string) ($claims->name ?? $socialiteUser->getName() ?? $email)) ?: $email,
                email: $email,
                issuer: $issuer,
            );
        } catch (EntraAuthenticationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new EntraAuthenticationException(
                'identity_provider_error',
                'Microsoft Entra authentication could not be completed.',
                [],
                $exception,
            );
        }
    }

    /** @param array<int, mixed> $candidates */
    private function firstUsableEmail(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return strtolower($candidate);
            }
        }

        return null;
    }
}
