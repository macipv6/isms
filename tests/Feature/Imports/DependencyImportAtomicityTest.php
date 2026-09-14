<?php

namespace Tests\Feature\Imports;

use App\Enums\DependencyImportance;
use App\Enums\ProjectStatus;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Dependencies\DependencyCycleDetector;
use App\Services\Dependencies\DependencyGraph;
use App\Services\Imports\RegisterImportConfirmer;
use App\Services\Imports\RegisterImportPreviewer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class DependencyImportAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_and_omitted_graph_changes_after_preview_reject_every_write(): void
    {
        foreach (['endpoint', 'graph'] as $case) {
            [$project, $actor] = $this->context();
            $p1 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
            $p2 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
            $p3 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P3']);
            $existing = $this->edge($project, $actor, $p1, $p2);
            $batch = $this->preview($project, $actor, [
                'process,P2,process,P3,critical,reviewed,true',
            ]);

            if ($case === 'endpoint') {
                $p3->update(['is_active' => false]);
            } else {
                $this->edge($project, $actor, $p3, $p1);
            }

            $this->expectImportRejection(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));

            $this->assertDatabaseMissing('dependency_edges', [
                'project_id' => $project->id,
                'source_process_id' => $p2->id,
                'target_process_id' => $p3->id,
            ]);
            $this->assertDatabaseHas('dependency_edges', ['id' => $existing->id, 'is_active' => true]);
            $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
        }
    }

    public function test_omitted_edge_endpoint_state_change_after_preview_rejects_every_write(): void
    {
        [$project, $actor] = $this->context();
        $p1 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $p2 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
        $p3 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P3']);
        $p4 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P4']);
        $omitted = $this->edge($project, $actor, $p1, $p2);
        $batch = $this->preview($project, $actor, [
            'process,P3,process,P4,critical,reviewed,true',
        ]);
        $this->assertSame(RegisterImportStatus::Pending, $batch->status);
        $p2->update(['is_active' => false]);

        $this->expectImportRejection(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));

        $this->assertDatabaseCount('dependency_edges', 1);
        $this->assertDatabaseHas('dependency_edges', ['id' => $omitted->id, 'is_active' => true]);
        $this->assertDatabaseMissing('dependency_edges', [
            'project_id' => $project->id,
            'source_process_id' => $p3->id,
            'target_process_id' => $p4->id,
        ]);
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
    }

    public function test_writable_project_state_change_after_preview_rejects_every_write(): void
    {
        [$project, $actor] = $this->context();
        $p1 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $p2 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
        $batch = $this->preview($project, $actor, [
            'process,P1,process,P2,critical,reviewed,true',
        ]);
        $this->assertSame(RegisterImportStatus::Pending, $batch->status);
        $project->update(['status' => ProjectStatus::Active]);

        $this->expectImportRejection(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));

        $this->assertDatabaseCount('dependency_edges', 0);
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
    }

    public function test_confirmable_final_graph_is_checked_once_before_the_first_edge_write(): void
    {
        [$project, $actor] = $this->context();
        $p1 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $p2 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
        $p3 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P3']);
        $p4 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P4']);
        $this->edge($project, $actor, $p1, $p2);
        $batch = $this->preview($project, $actor, [
            'process,P3,process,P4,critical,reviewed,true',
        ]);
        $this->assertSame(RegisterImportStatus::Pending, $batch->status);
        $detector = new class(app(DependencyGraph::class)) extends DependencyCycleDetector
        {
            public int $calls = 0;

            public int $databaseEdgesAtCheck = -1;

            public int $finalEdgesAtCheck = -1;

            public function assertAcyclic(array $existingEdges, array $candidateEdges): void
            {
                $this->calls++;
                $this->databaseEdgesAtCheck = DependencyEdge::query()->count();
                $this->finalEdgesAtCheck = count($existingEdges) + count($candidateEdges);
                parent::assertAcyclic($existingEdges, $candidateEdges);
            }
        };
        $this->app->instance(DependencyCycleDetector::class, $detector);

        app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $this->assertSame(1, $detector->calls);
        $this->assertSame(1, $detector->databaseEdgesAtCheck);
        $this->assertSame(2, $detector->finalEdgesAtCheck);
        $this->assertDatabaseCount('dependency_edges', 2);
    }

    public function test_confirmation_locks_batch_then_project_before_reading_or_writing_edges(): void
    {
        [$project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        $batch = $this->preview($project, $actor, ['process,P1,asset,A1,critical,,true']);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update') || str_starts_with($sql, 'insert into "dependency_edges"')) {
                $queries[] = $sql;
            }
        });

        app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $batchLock = array_find_key($queries, fn (string $sql): bool => str_contains($sql, 'from "register_import_batches"'));
        $projectLock = array_find_key($queries, fn (string $sql): bool => str_contains($sql, 'from "isms_projects"'));
        $edgeWrite = array_find_key($queries, fn (string $sql): bool => str_starts_with($sql, 'insert into "dependency_edges"'));
        $this->assertIsInt($batchLock);
        $this->assertIsInt($projectLock);
        $this->assertIsInt($edgeWrite);
        $this->assertLessThan($projectLock, $batchLock);
        $this->assertLessThan($edgeWrite, $projectLock);
        $this->assertDatabaseHas('dependency_edges', ['project_id' => $project->id, 'source_process_id' => $process->id]);
    }

    public function test_audit_failure_rolls_back_all_edges_and_batch_state(): void
    {
        [$project, $actor] = $this->context();
        $p1 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $p2 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        $batch = $this->preview($project, $actor, [
            'process,P1,process,P2,critical,secret one,true',
            'process,P2,asset,A1,supporting,secret two,true',
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

        $this->assertDatabaseCount('dependency_edges', 0);
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
        $this->assertNull($batch->fresh()->applied_at);
        $this->assertDatabaseCount('audit_events', $auditCount);
    }

    public function test_repeated_confirmation_never_reapplies_dependency_rows(): void
    {
        [$project, $actor] = $this->context();
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        $batch = $this->preview($project, $actor, ['process,P1,asset,A1,critical,,true']);
        app(RegisterImportConfirmer::class)->confirm($batch, $actor);
        $edge = DependencyEdge::query()->sole();
        $updatedAt = $edge->updated_at;
        $appliedAt = $batch->fresh()->applied_at;
        $auditCount = AuditEvent::query()->count();

        $this->expectImportRejection(fn () => app(RegisterImportConfirmer::class)->confirm($batch, $actor));

        $this->assertDatabaseCount('dependency_edges', 1);
        $this->assertTrue($edge->fresh()->updated_at->equalTo($updatedAt));
        $this->assertTrue($batch->fresh()->applied_at?->equalTo($appliedAt) ?? false);
        $this->assertDatabaseCount('audit_events', $auditCount);
    }

    /** @return array{IsmsProject, User} */
    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();

        return [$project, $actor];
    }

    /** @param list<string> $rows */
    private function preview(IsmsProject $project, User $actor, array $rows): RegisterImportBatch
    {
        $contents = "source_type,source_key,target_type,target_key,importance,reason,active\n".implode("\n", $rows)."\n";
        $path = tempnam(sys_get_temp_dir(), 'dependency-import-');
        file_put_contents($path, $contents);

        return app(RegisterImportPreviewer::class)->preview(
            $project,
            RegisterImportKind::Dependencies,
            new UploadedFile($path, 'dependencies.csv', 'text/csv', null, true),
            $actor,
        );
    }

    private function edge(IsmsProject $project, User $actor, BusinessProcess $source, BusinessProcess $target): DependencyEdge
    {
        return DependencyEdge::query()->create([
            'project_id' => $project->id,
            'source_process_id' => $source->id,
            'target_process_id' => $target->id,
            'importance' => DependencyImportance::Supporting,
            'is_active' => true,
            'created_by' => $actor->id,
        ]);
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
