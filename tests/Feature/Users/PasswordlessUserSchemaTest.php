<?php

namespace Tests\Feature\Users;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PasswordlessUserSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_table_has_no_password_column(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'id',
            'organization_id',
            'entra_tenant_id',
            'entra_object_id',
            'name',
            'email',
            'role',
            'is_active',
            'last_login_at',
        ]));

        $this->assertFalse(Schema::hasColumn('users', 'password'));
        $this->assertFalse(Schema::hasColumn('users', 'remember_token'));
    }
}
