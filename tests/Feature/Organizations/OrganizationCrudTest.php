<?php

namespace Tests\Feature\Organizations;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_customer_organizations_without_internal_organization(): void
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $admin = User::factory()->for($internal)->create(['role' => UserRole::Admin]);
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'name' => 'Muster GmbH',
        ]);

        $this->actingAs($admin)
            ->get('/organizations')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('organizations/Index')
                ->has('organizations', 1)
                ->where('organizations.0.id', $customer->id)
                ->where('organizations.0.name', 'Muster GmbH'));
    }

    public function test_admin_can_create_customer_organization(): void
    {
        $admin = $this->admin();

        $response = $this->actingAs($admin)->post('/organizations', [
            'name' => 'Muster GmbH',
            'industry' => 'Maschinenbau',
            'employee_count' => 42,
            'address' => 'Musterstraße 1, 12345 Berlin',
            'contact_name' => 'Max Mustermann',
            'contact_email' => 'max@example.test',
            'contact_phone' => '+49 30 123456',
            'notes' => 'Kickoff geplant.',
        ]);

        $organization = Organization::query()->where('name', 'Muster GmbH')->firstOrFail();

        $response->assertRedirect('/organizations/'.$organization->id);
        $this->assertSame('customer', $organization->organization_type);
        $this->assertNull($organization->entra_tenant_id);
        $this->assertTrue($organization->is_active);
        $this->assertNotSame('', $organization->slug);
    }

    public function test_admin_can_update_customer_organization(): void
    {
        $admin = $this->admin();
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
        ]);

        $this->actingAs($admin)
            ->put('/organizations/'.$customer->id, [
                'name' => 'Aktualisierte GmbH',
                'industry' => 'IT',
                'employee_count' => 55,
                'address' => 'Neue Straße 2',
                'contact_name' => 'Erika Beispiel',
                'contact_email' => 'erika@example.test',
                'contact_phone' => '+49 40 123456',
                'notes' => 'Daten aktualisiert.',
            ])
            ->assertRedirect('/organizations/'.$customer->id);

        $customer->refresh();
        $this->assertSame('Aktualisierte GmbH', $customer->name);
        $this->assertSame(55, $customer->employee_count);
        $this->assertSame('erika@example.test', $customer->contact_email);
    }

    public function test_admin_can_deactivate_customer_organization(): void
    {
        $admin = $this->admin();
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patch('/organizations/'.$customer->id.'/deactivate')
            ->assertRedirect('/organizations/'.$customer->id);

        $this->assertFalse($customer->fresh()->is_active);
    }

    public function test_organization_payload_is_validated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/organizations', [
                'name' => '',
                'employee_count' => -1,
                'contact_email' => 'not-an-email',
            ])
            ->assertSessionHasErrors(['name', 'employee_count', 'contact_email']);
    }

    private function admin(): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => UserRole::Admin]);
    }
}
