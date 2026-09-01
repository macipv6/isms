<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BootstrapConsultantUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_active_allow_list_user_without_password(): void
    {
        $tenantId = '11111111-1111-4111-8111-111111111111';
        $objectId = '22222222-2222-4222-8222-222222222222';

        $this->artisan('isms:bootstrap-user', [
            'tenant-id' => $tenantId,
            'object-id' => $objectId,
            'email' => 'admin@example.test',
            'name' => 'ISMS Admin',
            '--organization' => 'Consulting GmbH',
            '--role' => 'admin',
        ])->assertSuccessful();

        $user = User::query()->sole();

        $this->assertSame($tenantId, $user->entra_tenant_id);
        $this->assertSame($objectId, $user->entra_object_id);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->is_active);
    }
}
