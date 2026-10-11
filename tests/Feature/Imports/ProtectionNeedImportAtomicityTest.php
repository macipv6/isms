<?php

namespace Tests\Feature\Imports;

use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProtectionNeed;
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

class ProtectionNeedImportAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_rating_or_active_edge_change_after_preview_rejects_all_writes(): void
    {
        foreach (['rating', 'edge'] as $mutation) {
            [$project, $actor, $process, $asset] = $this->context();
            $batch = $this->preview($project, $actor);
            if ($mutation === 'rating') {
                ProtectionNeed::query()->create(['project_id' => $project->id, 'business_process_id' => $process->id, 'objective' => 'confidentiality', 'direct_level' => 'high', 'direct_rationale' => 'Changed after preview', 'status' => 'draft', 'lock_version' => 1]);
            } else {
                DependencyEdge::query()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id, 'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id]);
            }

            $this->expectRejected(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));
            $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
            $this->assertDatabaseMissing('protection_needs', ['asset_id' => $asset->id, 'direct_rationale' => 'Import asset']);
        }
    }

    public function test_audit_failure_rolls_back_every_rating_and_batch_transition(): void
    {
        [$project, $actor, $process, $asset] = $this->context();
        $batch = $this->preview($project, $actor);
        $audit = new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                if ($eventType === 'protection_needs_import.applied') {
                    throw new RuntimeException('Audit unavailable');
                }

                return parent::record($eventType, $actor, $context, $organizationId);
            }
        };
        $this->app->instance(AuditLogger::class, $audit);

        try {
            app(RegisterImportConfirmer::class)->confirm($batch, $actor);
            $this->fail('Audit failure was ignored.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
        $this->assertDatabaseMissing('protection_needs', ['business_process_id' => $process->id, 'direct_rationale' => 'Import process']);
        $this->assertDatabaseMissing('protection_needs', ['asset_id' => $asset->id, 'direct_rationale' => 'Import asset']);
    }

    public function test_changed_active_edge_metadata_also_makes_the_preview_stale(): void
    {
        [$project, $actor, , $asset] = $this->context();
        $other = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
        $edge = DependencyEdge::query()->create(['project_id' => $project->id, 'source_process_id' => $other->id, 'target_asset_id' => $asset->id, 'importance' => 'supporting', 'reason' => 'Before', 'is_active' => true, 'created_by' => $actor->id]);
        $batch = $this->preview($project, $actor);
        $edge->update(['reason' => 'After']);

        $this->expectRejected(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
    }

    public function test_corrupted_later_batch_row_rolls_back_the_entire_import(): void
    {
        [$project, $actor, $process, $asset] = $this->context();
        $batch = $this->preview($project, $actor);
        $payload = $batch->payload;
        $payload[1]['availability_rationale'] = '';
        $batch->update(['payload' => $payload]);

        $this->expectRejected(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));

        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
        $this->assertDatabaseMissing('protection_needs', ['business_process_id' => $process->id, 'direct_rationale' => 'Import process']);
        $this->assertDatabaseMissing('protection_needs', ['asset_id' => $asset->id, 'direct_rationale' => 'Import asset']);
    }

    /** @return array{IsmsProject, User, BusinessProcess, Asset} */
    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);

        return [$project, $actor, $process, $asset];
    }

    private function preview(IsmsProject $project, User $actor): RegisterImportBatch
    {
        $path = tempnam(sys_get_temp_dir(), 'needs-atomic-');
        file_put_contents($path, "object_type,object_key,confidentiality,confidentiality_rationale,integrity,integrity_rationale,availability,availability_rationale\nprocess,P1,high,Import process,normal,Check,normal,Spare\nasset,A1,normal,Import asset,normal,Check,normal,Spare\n");

        return app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::ProtectionNeeds, new UploadedFile($path, 'needs.csv', 'text/csv', null, true), $actor);
    }

    private function expectRejected(callable $action): void
    {
        try {
            $action();
            $this->fail('Stale preview was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Der Import kann nicht bestätigt werden.'], $exception->errors()['import']);
        }
    }
}
