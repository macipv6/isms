<?php

namespace Tests\Unit\Auth;

use App\Auth\Entra\Data\EntraIdentity;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EntraIdentityTest extends TestCase
{
    public function test_it_accepts_a_valid_entra_identity(): void
    {
        $identity = new EntraIdentity(
            tenantId: '11111111-1111-4111-8111-111111111111',
            objectId: '22222222-2222-4222-8222-222222222222',
            subject: 'entra-subject',
            name: 'ISMS Admin',
            email: 'admin@example.test',
            issuer: 'https://login.microsoftonline.com/11111111-1111-4111-8111-111111111111/v2.0',
        );

        $this->assertSame('22222222-2222-4222-8222-222222222222', $identity->objectId);
    }

    public function test_it_rejects_an_invalid_tenant_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EntraIdentity(
            tenantId: 'not-a-uuid',
            objectId: '22222222-2222-4222-8222-222222222222',
            subject: 'entra-subject',
            name: 'ISMS Admin',
            email: 'admin@example.test',
            issuer: 'https://issuer.example.test',
        );
    }

    public function test_it_rejects_an_invalid_object_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EntraIdentity(
            tenantId: '11111111-1111-4111-8111-111111111111',
            objectId: 'not-a-uuid',
            subject: 'entra-subject',
            name: 'ISMS Admin',
            email: 'admin@example.test',
            issuer: 'https://issuer.example.test',
        );
    }
}
