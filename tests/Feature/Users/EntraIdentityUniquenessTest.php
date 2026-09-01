<?php

namespace Tests\Feature\Users;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntraIdentityUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_and_object_id_pair_is_unique(): void
    {
        $first = User::factory()->create();

        $this->expectException(QueryException::class);

        User::factory()->create([
            'entra_tenant_id' => $first->entra_tenant_id,
            'entra_object_id' => $first->entra_object_id,
        ]);
    }
}
