<?php

namespace Tests\Feature\Organizations;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_creation_is_audited_for_target_customer(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/organizations', [
            'name' => 'Muster GmbH',
            'industry' => 'IT',
            'notes' => 'Sensitive customer note that must not enter audit context.',
        ])->assertRedirect();

        $customer = Organization::query()->where('name', 'Muster GmbH')->sole();
        $event = AuditEvent::query()->where('event_type', 'organization.created')->sole();

        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame($customer->id, $event->organization_id);
        $this->assertContains('name', $event->context['changed_fields']);
        $this->assertContains('notes', $event->context['changed_fields']);
        $this->assertStringNotContainsString('Sensitive customer note', json_encode($event->context, JSON_THROW_ON_ERROR));
    }

    public function test_organization_update_audits_only_changed_field_names(): void
    {
        $admin = $this->admin();
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'name' => 'Alt GmbH',
            'industry' => 'Handel',
            'notes' => null,
        ]);

        $this->actingAs($admin)->put('/organizations/'.$customer->id, [
            'name' => 'Neu GmbH',
            'industry' => 'Handel',
            'notes' => 'Confidential update content.',
        ])->assertRedirect();

        $event = AuditEvent::query()->where('event_type', 'organization.updated')->sole();

        $this->assertSame($customer->id, $event->organization_id);
        $this->assertEqualsCanonicalizing(['name', 'notes'], $event->context['changed_fields']);
        $this->assertStringNotContainsString('Confidential update content', json_encode($event->context, JSON_THROW_ON_ERROR));
    }

    public function test_organization_deactivation_is_audited(): void
    {
        $admin = $this->admin();
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patch('/organizations/'.$customer->id.'/deactivate')
            ->assertRedirect();

        $event = AuditEvent::query()->where('event_type', 'organization.deactivated')->sole();

        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame($customer->id, $event->organization_id);
        $this->assertSame(['is_active'], $event->context['changed_fields']);
    }

    private function admin(): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => UserRole::Admin]);
    }
}
