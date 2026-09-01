<?php

namespace App\Http\Controllers\Auth;

use App\Auth\Entra\Contracts\EntraIdentityProvider;
use App\Auth\Entra\Exceptions\EntraAuthenticationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse as LaravelRedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;

class EntraAuthController extends Controller
{
    private const DENIAL_REASONS = [
        'tenant_mismatch',
        'user_not_allowed',
        'user_inactive',
        'identity_provider_error',
    ];

    public function login(): Response
    {
        return Inertia::render('auth/Login');
    }

    public function redirect(EntraIdentityProvider $provider): RedirectResponse
    {
        return $provider->redirect();
    }

    public function callback(Request $request, EntraIdentityProvider $provider, AuditLogger $audit): LaravelRedirectResponse
    {
        try {
            $identity = $provider->identityFromCallback();
        } catch (EntraAuthenticationException $exception) {
            $reason = in_array($exception->reason, self::DENIAL_REASONS, true)
                ? $exception->reason
                : 'identity_provider_error';

            $audit->record('auth.login_denied', null, [
                'reason' => $reason,
                'entra_tenant_id' => $exception->context['entra_tenant_id'] ?? null,
            ]);

            abort(403, 'Microsoft authentication failed.');
        }

        $configuredTenant = (string) config('services.microsoft.tenant');
        if ($configuredTenant === '' || ! hash_equals(strtolower($configuredTenant), strtolower($identity->tenantId))) {
            $audit->record('auth.login_denied', null, [
                'reason' => 'tenant_mismatch',
                'entra_tenant_id' => $identity->tenantId,
            ]);
            abort(403, 'Tenant is not allowed.');
        }

        $user = User::query()
            ->where('entra_tenant_id', $identity->tenantId)
            ->where('entra_object_id', $identity->objectId)
            ->first();

        if ($user === null) {
            $audit->record('auth.login_denied', null, [
                'reason' => 'user_not_allowed',
                'entra_tenant_id' => $identity->tenantId,
            ]);
            abort(403, 'User is not allowed.');
        }

        if (! $user->is_active) {
            $audit->record('auth.login_denied', $user, [
                'reason' => 'user_inactive',
                'entra_tenant_id' => $identity->tenantId,
            ]);
            abort(403, 'User is inactive.');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record('auth.login_succeeded', $user);

        return redirect()->intended('/dashboard');
    }

    public function logout(Request $request, AuditLogger $audit): LaravelRedirectResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            $audit->record('auth.logout', $user);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
