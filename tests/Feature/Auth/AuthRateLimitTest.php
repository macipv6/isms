<?php

namespace Tests\Feature\Auth;

use App\Auth\Entra\Contracts\EntraIdentityProvider;
use App\Auth\Entra\Data\EntraIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_microsoft_auth_redirect_is_limited_per_ip(): void
    {
        $this->app->bind(EntraIdentityProvider::class, fn () => new class implements EntraIdentityProvider {
            public function redirect(): RedirectResponse { return new RedirectResponse('/provider'); }
            public function identityFromCallback(): EntraIdentity { throw new \LogicException('Not used.'); }
        });

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->get('/auth/microsoft/redirect')
                ->assertRedirect('/provider');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->get('/auth/microsoft/redirect')
            ->assertTooManyRequests();
    }
}
