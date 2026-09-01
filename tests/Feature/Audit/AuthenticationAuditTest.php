<?php

namespace Tests\Feature\Audit;

use App\Auth\Entra\Contracts\EntraIdentityProvider;
use App\Auth\Entra\Data\EntraIdentity;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

class AuthenticationAuditTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT_ID = '11111111-1111-4111-8111-111111111111';
    private const OBJECT_ID = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.microsoft.tenant', self::TENANT_ID);
    }

    public function test_successful_login_is_audited_without_token_material(): void
    {
        $user = User::factory()->create([
            'entra_tenant_id' => self::TENANT_ID,
            'entra_object_id' => self::OBJECT_ID,
        ]);
        $this->bindIdentityProvider();

        $this->get('/auth/microsoft/callback')->assertRedirect('/dashboard');

        $event = AuditEvent::query()->sole();
        $this->assertSame('auth.login_succeeded', $event->event_type);
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame($user->organization_id, $event->organization_id);
        $this->assertArrayNotHasKey('access_token', $event->context);
        $this->assertArrayNotHasKey('refresh_token', $event->context);
        $this->assertArrayNotHasKey('id_token', $event->context);
    }

    public function test_denied_login_is_audited(): void
    {
        $this->bindIdentityProvider();

        $this->get('/auth/microsoft/callback')->assertForbidden();

        $event = AuditEvent::query()->sole();
        $this->assertSame('auth.login_denied', $event->event_type);
        $this->assertSame('user_not_allowed', $event->context['reason']);
        $this->assertSame(self::TENANT_ID, $event->context['entra_tenant_id']);
    }

    public function test_logout_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $event = AuditEvent::query()->sole();
        $this->assertSame('auth.logout', $event->event_type);
        $this->assertSame($user->id, $event->actor_user_id);
    }

    private function bindIdentityProvider(): void
    {
        $identity = new EntraIdentity(
            tenantId: self::TENANT_ID,
            objectId: self::OBJECT_ID,
            subject: 'entra-subject',
            name: 'ISMS Admin',
            email: 'admin@example.test',
            issuer: 'https://login.microsoftonline.com/'.self::TENANT_ID.'/v2.0',
        );

        $this->app->bind(EntraIdentityProvider::class, fn () => new class($identity) implements EntraIdentityProvider {
            public function __construct(private EntraIdentity $identity) {}
            public function redirect(): RedirectResponse { return new RedirectResponse('/provider'); }
            public function identityFromCallback(): EntraIdentity { return $this->identity; }
        });
    }
}
