<?php

namespace Tests\Feature\Organizations;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultant_can_view_customer_organizations(): void
    {
        $consultant = $this->consultant();
        Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
        ]);

        $this->actingAs($consultant)
            ->get('/organizations')
            ->assertOk();
    }

    public function test_consultant_cannot_create_customer_organization(): void
    {
        $consultant = $this->consultant();

        $this->actingAs($consultant)
            ->post('/organizations', ['name' => 'Nicht erlaubt'])
            ->assertForbidden();
    }

    public function test_consultant_cannot_update_customer_organization(): void
    {
        $consultant = $this->consultant();
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
        ]);

        $this->actingAs($consultant)
            ->put('/organizations/'.$customer->id, ['name' => 'Manipuliert'])
            ->assertForbidden();

        $this->assertNotSame('Manipuliert', $customer->fresh()->name);
    }

    public function test_consultant_cannot_deactivate_customer_organization(): void
    {
        $consultant = $this->consultant();
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);

        $this->actingAs($consultant)
            ->patch('/organizations/'.$customer->id.'/deactivate')
            ->assertForbidden();

        $this->assertTrue($customer->fresh()->is_active);
    }

    public function test_internal_organization_cannot_be_accessed_through_customer_route(): void
    {
        $admin = $this->admin();
        $otherInternal = Organization::factory()->create(['organization_type' => 'internal']);

        $this->actingAs($admin)
            ->get('/organizations/'.$otherInternal->id)
            ->assertNotFound();
    }

    private function consultant(): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => UserRole::Consultant]);
    }

    private function admin(): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => UserRole::Admin]);
    }
}
