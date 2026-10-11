<?php

namespace Tests\Feature\Imports;

use App\Enums\RegisterImportKind;
use App\Models\RegisterImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterImportTimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_batches_remain_expired_after_database_reload_for_every_import_kind(): void
    {
        foreach (RegisterImportKind::cases() as $kind) {
            $expiry = now()->subMinute()->startOfSecond();
            $batch = RegisterImportBatch::factory()->create(['kind' => $kind, 'expires_at' => $expiry]);

            $this->assertSame($expiry->getTimestamp(), $batch->fresh()->expires_at->getTimestamp());
            $this->assertTrue($batch->fresh()->expires_at->isPast());
        }
    }
}
