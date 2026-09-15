<?php

namespace Tests\Feature\Imports;

use App\Enums\DependencyImportance;
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
use App\Services\Imports\RegisterImportConfirmer;
use App\Services\Imports\RegisterImportPreviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DependencyImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_applies_all_shapes_and_preserves_unchanged_omitted_and_historical_edges(): void
    {
        Carbon::setTestNow('2026-09-13 12:00:00');
        [$project, $actor] = $this->context();
        $p1 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $p2 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
        $p3 = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P3']);
        $a1 = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        $a2 = Asset::factory()->for($project, 'project')->create(['key' => 'A2']);
        $a3 = Asset::factory()->for($project, 'project')->create(['key' => 'A3']);

        $metadata = $this->edge($project, $actor, $p1, $p2, true, DependencyImportance::Supporting, 'before');
        $unchanged = $this->edge($project, $actor, $p1, $a1, true, DependencyImportance::Critical, 'stable');
        $deactivated = $this->edge($project, $actor, $a1, $a2, true, DependencyImportance::Supporting, null);
        $historical = $this->edge($project, $actor, $a2, $a3, false, DependencyImportance::Supporting, 'old');
        $omitted = $this->edge($project, $actor, $p3, $a3, true, DependencyImportance::Supporting, 'omitted');
        $unchanged->refresh();
        $omitted->refresh();
        $unchangedAt = $unchanged->updated_at;
        $omittedAt = $omitted->updated_at;

        $batch = $this->preview($project, $actor, [
            'asset,a2,asset,a3,critical,restored,true',
            'process,p2,asset,a2,supporting,new,true',
            'process,p1,asset,a1,critical,stable,true',
            'asset,a1,asset,a2,supporting,,false',
            'process,p1,process,p2,critical,after,true',
        ]);

        $this->assertSame(RegisterImportStatus::Pending, $batch->status);
        $this->assertEquals(
            ['new' => 1, 'changed' => 3, 'unchanged' => 1, 'invalid' => 0],
            $batch->summary['counts'],
        );
        $this->assertSame(['changed', 'new', 'unchanged', 'changed', 'changed'], array_column($batch->summary['rows'], 'category'));

        Carbon::setTestNow('2026-09-13 12:05:00');
        $confirmed = app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $this->assertSame(RegisterImportStatus::Applied, $confirmed->status);
        $this->assertTrue($confirmed->applied_at?->equalTo(now('UTC')) ?? false);
        $this->assertDatabaseHas('dependency_edges', [
            'project_id' => $project->id,
            'source_process_id' => $p2->id,
            'target_asset_id' => $a2->id,
            'importance' => DependencyImportance::Supporting->value,
            'reason' => 'new',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('dependency_edges', [
            'id' => $metadata->id,
            'importance' => DependencyImportance::Critical->value,
            'reason' => 'after',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('dependency_edges', ['id' => $deactivated->id, 'is_active' => false]);
        $this->assertDatabaseHas('dependency_edges', [
            'id' => $historical->id,
            'importance' => DependencyImportance::Critical->value,
            'reason' => 'restored',
            'is_active' => true,
        ]);
        $this->assertSame($unchangedAt->toIso8601String(), $unchanged->fresh()->updated_at->toIso8601String());
        $this->assertSame($omittedAt->toIso8601String(), $omitted->fresh()->updated_at->toIso8601String());
        $this->assertDatabaseCount('dependency_edges', 6);

        $applied = AuditEvent::query()->where('event_type', 'register_import.applied')->sole();
        $this->assertSame($project->organization_id, $applied->organization_id);
        $this->assertEquals([
            'project_id' => $project->id,
            'import_batch_id' => $batch->id,
            'import_kind' => RegisterImportKind::Dependencies->value,
            'row_counts' => ['new' => 1, 'changed' => 3, 'unchanged' => 1, 'invalid' => 0],
        ], $applied->context);
        $this->assertDatabaseCount('audit_events', 2);
        $encodedAudit = AuditEvent::query()->get()->toJson();
        $this->assertStringNotContainsString('restored', $encodedAudit);
        $this->assertStringNotContainsString('after', $encodedAudit);
        $this->assertStringNotContainsString('stable', $encodedAudit);
    }

    public function test_topologically_unordered_dependency_rows_are_resolved_by_normalized_project_keys(): void
    {
        [$project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'ROOT']);
        $first = Asset::factory()->for($project, 'project')->create(['key' => 'FIRST']);
        $second = Asset::factory()->for($project, 'project')->create(['key' => 'SECOND']);
        $third = Asset::factory()->for($project, 'project')->create(['key' => 'THIRD']);
        $batch = $this->preview($project, $actor, [
            'asset, second ,asset, third ,supporting,,true',
            'asset, first ,asset, second ,critical,,true',
            'process, root ,asset, first ,critical,,true',
        ]);

        app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $this->assertDatabaseHas('dependency_edges', ['source_asset_id' => $second->id, 'target_asset_id' => $third->id, 'is_active' => true]);
        $this->assertDatabaseHas('dependency_edges', ['source_asset_id' => $first->id, 'target_asset_id' => $second->id, 'is_active' => true]);
        $this->assertDatabaseHas('dependency_edges', ['source_process_id' => $process->id, 'target_asset_id' => $first->id, 'is_active' => true]);
    }

    public function test_existing_edge_can_be_deactivated_and_its_metadata_updated_after_an_endpoint_becomes_inactive(): void
    {
        [$project, $actor] = $this->context();
        $source = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $target = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        $edge = $this->edge($project, $actor, $source, $target, true, DependencyImportance::Supporting, 'before');
        $target->update(['is_active' => false]);

        $batch = $this->preview($project, $actor, [
            'process,P1,asset,A1,critical,after,false',
        ]);

        $this->assertSame(RegisterImportStatus::Pending, $batch->status);
        $this->assertEquals(['new' => 0, 'changed' => 1, 'unchanged' => 0, 'invalid' => 0], $batch->summary['counts']);

        app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $this->assertDatabaseHas('dependency_edges', [
            'id' => $edge->id,
            'importance' => DependencyImportance::Critical->value,
            'reason' => 'after',
            'is_active' => false,
        ]);
        $this->assertDatabaseCount('dependency_edges', 1);
    }

    public function test_existing_active_edge_allows_metadata_only_import_after_an_endpoint_becomes_inactive(): void
    {
        [$project, $actor] = $this->context();
        $source = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $target = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        $edge = $this->edge($project, $actor, $source, $target, true, DependencyImportance::Supporting, 'before');
        $source->update(['is_active' => false]);

        $batch = $this->preview($project, $actor, [
            'process,P1,asset,A1,critical,after,true',
        ]);

        $this->assertSame(RegisterImportStatus::Pending, $batch->status);

        app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $this->assertDatabaseHas('dependency_edges', [
            'id' => $edge->id,
            'importance' => DependencyImportance::Critical->value,
            'reason' => 'after',
            'is_active' => true,
        ]);
        $this->assertDatabaseCount('dependency_edges', 1);
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

    private function edge(
        IsmsProject $project,
        User $actor,
        BusinessProcess|Asset $source,
        BusinessProcess|Asset $target,
        bool $active,
        DependencyImportance $importance,
        ?string $reason,
    ): DependencyEdge {
        return DependencyEdge::query()->create([
            'project_id' => $project->id,
            'source_process_id' => $source instanceof BusinessProcess ? $source->id : null,
            'source_asset_id' => $source instanceof Asset ? $source->id : null,
            'target_process_id' => $target instanceof BusinessProcess ? $target->id : null,
            'target_asset_id' => $target instanceof Asset ? $target->id : null,
            'importance' => $importance,
            'reason' => $reason,
            'is_active' => $active,
            'created_by' => $actor->id,
        ]);
    }
}
