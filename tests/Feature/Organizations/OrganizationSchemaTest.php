<?php

namespace Tests\Feature\Organizations;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizations_table_contains_customer_profile_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('organizations', [
            'address',
            'contact_name',
            'contact_email',
            'contact_phone',
            'notes',
        ]));
    }

    public function test_customer_profile_fields_are_mass_assignable(): void
    {
        $organization = new Organization([
            'address' => 'Musterstraße 1, 12345 Berlin',
            'contact_name' => 'Max Mustermann',
            'contact_email' => 'max@example.test',
            'contact_phone' => '+49 30 123456',
            'notes' => 'Erstgespräch abgeschlossen.',
        ]);

        $this->assertSame('Musterstraße 1, 12345 Berlin', $organization->address);
        $this->assertSame('Max Mustermann', $organization->contact_name);
        $this->assertSame('max@example.test', $organization->contact_email);
        $this->assertSame('+49 30 123456', $organization->contact_phone);
        $this->assertSame('Erstgespräch abgeschlossen.', $organization->notes);
    }
}
