<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

class BootstrapConsultantUser extends Command
{
    protected $signature = 'isms:bootstrap-user
        {tenant-id : Microsoft Entra tenant UUID}
        {object-id : Microsoft Entra object UUID}
        {email : User email address}
        {name : User display name}
        {--organization=ISMS Consulting : Consultant organization name}
        {--role=admin : admin or consultant}';

    protected $description = 'Create or update a locally allow-listed Microsoft Entra consultant user';

    public function handle(): int
    {
        $tenantId = (string) $this->argument('tenant-id');
        $objectId = (string) $this->argument('object-id');
        $email = (string) $this->argument('email');
        $name = trim((string) $this->argument('name'));
        $organizationName = trim((string) $this->option('organization'));
        $role = UserRole::tryFrom((string) $this->option('role'));

        if (! Uuid::isValid($tenantId) || ! Uuid::isValid($objectId)) {
            $this->error('Tenant ID and Object ID must be valid UUIDs.');

            return self::FAILURE;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Email address is invalid.');

            return self::FAILURE;
        }

        if ($name === '' || $organizationName === '') {
            $this->error('Name and organization must not be empty.');

            return self::FAILURE;
        }

        if ($role === null) {
            $this->error('Role must be admin or consultant.');

            return self::FAILURE;
        }

        $slug = Str::slug($organizationName);
        if ($slug === '') {
            $this->error('Organization name cannot be converted to a valid slug.');

            return self::FAILURE;
        }

        $organization = Organization::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $organizationName, 'entra_tenant_id' => $tenantId, 'is_active' => true],
        );

        $organization->forceFill(['entra_tenant_id' => $tenantId])->save();

        User::query()->updateOrCreate(
            ['entra_tenant_id' => $tenantId, 'entra_object_id' => $objectId],
            [
                'organization_id' => $organization->id,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'is_active' => true,
            ],
        );

        $this->info('Consultant user is allow-listed.');

        return self::SUCCESS;
    }
}
