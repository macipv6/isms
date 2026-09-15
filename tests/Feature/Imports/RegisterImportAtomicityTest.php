<?php

namespace Tests\Feature\Imports;

use App\Enums\ProjectStatus;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Models\AuditEvent;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Imports\RegisterImportConfirmer;
use App\Services\Imports\RegisterImportPreviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class RegisterImportAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_new_key_conflict_rejects_confirmation_without_partial_register_writes(): void
    {
        [, $project, $actor] = $this->context();
        $batch = $this->processPreview($project, $actor, [
            'FIRST,First,,,,true',
            'CONFLICT,Reviewed new row,,,,true',
        ]);
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'CONFLICT', 'name' => 'Concurrent row']);

        $this->expectImportRejection(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));

        $this->assertDatabaseMissing('business_processes', ['project_id' => $project->id, 'key' => 'FIRST']);
        $this->assertDatabaseHas('business_processes', ['project_id' => $project->id, 'key' => 'CONFLICT', 'name' => 'Concurrent row']);
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
    }

    public function test_equal_count_category_swap_rejects_confirmation_without_partial_register_writes(): void
    {
        [, $project, $actor] = $this->context();
        $wasChanged = BusinessProcess::factory()->for($project, 'project')->create([
            'key' => 'WAS-CHANGED',
            'name' => 'Before',
            'description' => null,
            'owner_name' => null,
            'owner_email' => null,
            'is_active' => true,
        ]);
        $wasUnchanged = BusinessProcess::factory()->for($project, 'project')->create([
            'key' => 'WAS-UNCHANGED',
            'name' => 'Stable',
            'description' => null,
            'owner_name' => null,
            'owner_email' => null,
            'is_active' => true,
        ]);
        $batch = $this->processPreview($project, $actor, [
            'NEW,Must not apply,,,,true',
            'WAS-CHANGED,Reviewed change,,,,true',
            'WAS-UNCHANGED,Stable,,,,true',
        ]);

        $wasChanged->update(['name' => 'Reviewed change']);
        $wasUnchanged->update(['name' => 'Concurrent change']);

        $this->expectImportRejection(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));

        $this->assertDatabaseMissing('business_processes', ['project_id' => $project->id, 'key' => 'NEW']);
        $this->assertDatabaseHas('business_processes', ['id' => $wasChanged->id, 'name' => 'Reviewed change']);
        $this->assertDatabaseHas('business_processes', ['id' => $wasUnchanged->id, 'name' => 'Concurrent change']);
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
    }

    public function test_changed_row_with_different_current_values_rejects_confirmation_without_partial_register_writes(): void
    {
        [, $project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create([
            'key' => 'EXISTING',
            'name' => 'Preview baseline',
            'description' => 'Original description',
            'owner_name' => 'Original owner',
            'owner_email' => 'original@example.test',
            'is_active' => true,
        ]);
        $batch = $this->processPreview($project, $actor, [
            'NEW,Must not apply,,,,true',
            'EXISTING,Reviewed change,Reviewed description,Reviewed owner,reviewed@example.test,false',
        ]);

        $process->update([
            'name' => 'Concurrent change',
            'description' => 'Concurrent description',
            'owner_name' => 'Concurrent owner',
            'owner_email' => 'concurrent@example.test',
            'is_active' => false,
        ]);

        $this->expectImportRejection(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));

        $this->assertDatabaseMissing('business_processes', ['project_id' => $project->id, 'key' => 'NEW']);
        $this->assertDatabaseHas('business_processes', [
            'id' => $process->id,
            'name' => 'Concurrent change',
            'description' => 'Concurrent description',
            'owner_name' => 'Concurrent owner',
            'owner_email' => 'concurrent@example.test',
            'is_active' => false,
        ]);
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
    }

    public function test_expired_or_no_longer_writable_batches_make_no_register_or_batch_changes(): void
    {
        foreach (['expired', 'project', 'customer'] as $case) {
            [$customer, $project, $actor] = $this->context();
            $batch = $this->processPreview($project, $actor, ["{$case}-KEY,{$case},,,,true"]);
            match ($case) {
                'expired' => $batch->update(['expires_at' => now('UTC')->subSecond()]),
                'project' => $project->update(['status' => ProjectStatus::Completed]),
                'customer' => $customer->update(['is_active' => false]),
            };

            $this->expectImportRejection(fn () => app(RegisterImportConfirmer::class)->confirm($batch->fresh(), $actor));

            $this->assertDatabaseMissing('business_processes', ['project_id' => $project->id, 'key' => strtoupper("{$case}-KEY")]);
            $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
        }
    }

    public function test_invalid_later_row_rolls_back_an_earlier_valid_upsert(): void
    {
        [, $project, $actor] = $this->context();
        $batch = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            'kind' => RegisterImportKind::Processes,
            'payload' => [
                ['key' => 'FIRST', 'name' => 'First', 'description' => null, 'owner_name' => null, 'owner_email' => null, 'active' => true],
                ['key' => 'INVALID', 'name' => '', 'description' => null, 'owner_name' => null, 'owner_email' => null, 'active' => true],
            ],
            'summary' => ['counts' => ['new' => 2, 'changed' => 0, 'unchanged' => 0, 'invalid' => 0], 'rows' => []],
        ]);

        $this->expectImportRejection(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));

        $this->assertDatabaseMissing('business_processes', ['project_id' => $project->id, 'key' => 'FIRST']);
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
    }

    public function test_audit_failure_rolls_back_every_upsert_and_applied_batch_state(): void
    {
        [, $project, $actor] = $this->context();
        $batch = $this->processPreview($project, $actor, [
            'FIRST,First,,,,true',
            'SECOND,Second,,,,true',
        ]);
        $auditCount = AuditEvent::query()->count();
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                throw new RuntimeException('audit unavailable');
            }
        });

        try {
            app(RegisterImportConfirmer::class)->confirm($batch, $actor);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertDatabaseMissing('business_processes', ['project_id' => $project->id, 'key' => 'FIRST']);
        $this->assertDatabaseMissing('business_processes', ['project_id' => $project->id, 'key' => 'SECOND']);
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
        $this->assertNull($batch->fresh()->applied_at);
        $this->assertDatabaseCount('audit_events', $auditCount);
    }

    /** @return array{Organization, IsmsProject, User} */
    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();

        return [$customer, $project, $actor];
    }

    /** @param list<string> $rows */
    private function processPreview(IsmsProject $project, User $actor, array $rows): RegisterImportBatch
    {
        $contents = "key,name,description,owner_name,owner_email,active\n".implode("\n", $rows)."\n";
        $path = tempnam(sys_get_temp_dir(), 'register-import-');
        file_put_contents($path, $contents);

        return app(RegisterImportPreviewer::class)->preview(
            $project,
            RegisterImportKind::Processes,
            new UploadedFile($path, 'processes.csv', 'text/csv', null, true),
            $actor,
        );
    }

    private function expectImportRejection(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected import rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('import', $exception->errors());
        }
    }
}
