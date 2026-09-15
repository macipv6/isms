<?php

namespace Tests\Feature\Imports;

use App\Enums\RegisterImportStatus;
use App\Models\AuditEvent;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurgeRegisterImportBatchesTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_expires_pending_once_deletes_old_terminal_batches_and_preserves_live_pending_batches(): void
    {
        Carbon::setTestNow('2026-09-12 12:00:00');
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $attributes = ['payload' => [['key' => 'SECRET']], 'summary' => ['rows' => [['value' => 'SECRET']]]];
        $pending = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            ...$attributes, 'status' => RegisterImportStatus::Pending, 'expires_at' => now('UTC')->subMinute(),
        ]);
        $live = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            ...$attributes, 'status' => RegisterImportStatus::Pending, 'expires_at' => now('UTC')->addMinute(),
        ]);
        $expired = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            ...$attributes, 'status' => RegisterImportStatus::Expired, 'expires_at' => now('UTC')->subMinute(),
        ]);
        $applied = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            ...$attributes, 'status' => RegisterImportStatus::Applied, 'expires_at' => now('UTC')->subMinute(), 'applied_at' => now('UTC')->subMinute(),
        ]);
        $rejected = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            ...$attributes, 'status' => RegisterImportStatus::Rejected, 'expires_at' => now('UTC')->subMinute(),
        ]);

        $this->artisan('register-imports:purge')->assertSuccessful();

        $pending->refresh();
        $this->assertSame(RegisterImportStatus::Expired, $pending->status);
        $this->assertSame([], $pending->payload);
        $this->assertSame([], $pending->summary);
        $this->assertDatabaseHas('register_import_batches', ['id' => $live->id, 'status' => RegisterImportStatus::Pending->value]);
        foreach ([$expired, $applied, $rejected] as $deleted) {
            $this->assertDatabaseMissing('register_import_batches', ['id' => $deleted->id]);
        }
        $this->assertDatabaseCount('audit_events', 1);
        $audit = AuditEvent::query()->sole();
        $this->assertSame('register_import.expired', $audit->event_type);
        $this->assertSame($customer->id, $audit->organization_id);
        $this->assertStringNotContainsString('SECRET', $audit->toJson());

        $this->artisan('register-imports:purge')->assertSuccessful();
        $this->assertDatabaseCount('audit_events', 1);
    }
}
