<?php

namespace Tests\Feature\Auth;

use App\Auth\Entra\Contracts\EntraIdentityProvider;
use App\Auth\Entra\Data\EntraIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class EntraAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ID = '11111111-1111-4111-8111-111111111111';

    private const OBJECT_ID = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.microsoft.tenant', self::TENANT_ID);
    }

    public function test_login_page_renders_microsoft_only_login(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('auth/Login'));
    }

    public function test_redirect_delegates_to_identity_provider(): void
    {
        $this->bindIdentityProvider($this->identity(), redirectTo: 'https://login.example.test');

        $this->get('/auth/microsoft/redirect')
            ->assertRedirect('https://login.example.test');
    }

    public function test_callback_rejects_wrong_tenant(): void
    {
        $this->bindIdentityProvider($this->identity(tenantId: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));

        $this->get('/auth/microsoft/callback')->assertForbidden();
        $this->assertGuest();
    }

    public function test_callback_rejects_user_absent_from_local_allow_list(): void
    {
        $this->bindIdentityProvider($this->identity());

        $this->get('/auth/microsoft/callback')->assertForbidden();
        $this->assertGuest();
    }

    public function test_callback_rejects_inactive_local_user(): void
    {
        User::factory()->create([
            'entra_tenant_id' => self::TENANT_ID,
            'entra_object_id' => self::OBJECT_ID,
            'is_active' => false,
        ]);
        $this->bindIdentityProvider($this->identity());

        $this->get('/auth/microsoft/callback')->assertForbidden();
        $this->assertGuest();
    }

    public function test_callback_accepts_active_allow_list_user_and_updates_last_login(): void
    {
        $user = User::factory()->create([
            'entra_tenant_id' => self::TENANT_ID,
            'entra_object_id' => self::OBJECT_ID,
            'is_active' => true,
            'last_login_at' => null,
        ]);
        $this->bindIdentityProvider($this->identity());

        $response = $this->get('/auth/microsoft/callback');

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    private function identity(?string $tenantId = null): EntraIdentity
    {
        $tenantId ??= self::TENANT_ID;

        return new EntraIdentity(
            tenantId: $tenantId,
            objectId: self::OBJECT_ID,
            subject: 'entra-subject',
            name: 'ISMS Admin',
            email: 'admin@example.test',
            issuer: "https://login.microsoftonline.com/{$tenantId}/v2.0",
        );
    }

    private function bindIdentityProvider(EntraIdentity $identity, string $redirectTo = '/provider'): void
    {
        $this->app->bind(EntraIdentityProvider::class, fn () => new class($identity, $redirectTo) implements EntraIdentityProvider
        {
            public function __construct(private EntraIdentity $identity, private string $redirectTo) {}

            public function redirect(): RedirectResponse
            {
                return new RedirectResponse($this->redirectTo);
            }

            public function identityFromCallback(): EntraIdentity
            {
                return $this->identity;
            }
        });
    }
}
