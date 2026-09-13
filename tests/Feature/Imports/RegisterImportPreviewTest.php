<?php

namespace Tests\Feature\Imports;

use App\Enums\AssetType;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Imports\RegisterImportPreviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RegisterImportPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_preview_categorizes_canonical_rows_without_writing_registers(): void
    {
        Carbon::setTestNow('2026-09-12 10:00:00');
        [, $project, $actor] = $this->context();
        BusinessProcess::factory()->for($project, 'project')->create([
            'key' => 'UNCHANGED', 'name' => 'Unchanged', 'description' => null,
            'owner_name' => null, 'owner_email' => null, 'is_active' => true,
        ]);
        BusinessProcess::factory()->for($project, 'project')->create([
            'key' => 'CHANGED', 'name' => 'Before', 'description' => null,
            'owner_name' => null, 'owner_email' => null, 'is_active' => true,
        ]);
        $contents = implode("\n", [
            'key,name,description,owner_name,owner_email,active',
            'new,New process,,,,true',
            'changed,After,,,,true',
            'unchanged,Unchanged,,,,true',
        ])."\n";

        $batch = app(RegisterImportPreviewer::class)->preview(
            $project,
            RegisterImportKind::Processes,
            $this->upload('processes.csv', $contents),
            $actor,
        );

        $this->assertSame(RegisterImportStatus::Pending, $batch->status);
        $this->assertSame(hash('sha256', $contents), $batch->sha256);
        $this->assertTrue($batch->expires_at->equalTo(now()->addMinutes(30)));
        $this->assertEquals(['new' => 1, 'changed' => 1, 'unchanged' => 1, 'invalid' => 0], $batch->summary['counts']);
        $this->assertSame(['NEW', 'CHANGED', 'UNCHANGED'], array_column($batch->payload, 'key'));
        $this->assertSame(['new', 'changed', 'unchanged'], array_column($batch->summary['rows'], 'category'));
        $this->assertDatabaseCount('business_processes', 2);
        $this->assertDatabaseCount('register_import_batches', 1);
    }

    public function test_asset_validation_creates_a_rejected_bounded_preview_without_raw_file_retention(): void
    {
        [, $project, $actor] = $this->context();
        $rows = [];
        for ($index = 0; $index < 205; $index++) {
            $rows[] = "ASSET-{$index},Asset {$index},invalid-type,,,,true";
        }
        $contents = "key,name,type,description,owner_name,owner_email,active\n".implode("\n", $rows)."\n";

        $batch = app(RegisterImportPreviewer::class)->preview(
            $project,
            RegisterImportKind::Assets,
            $this->upload('assets.csv', $contents),
            $actor,
        );

        $this->assertSame(RegisterImportStatus::Rejected, $batch->status);
        $this->assertSame([], $batch->payload);
        $this->assertLessThanOrEqual(200, count($batch->summary['rows']));
        $this->assertSame(205, $batch->summary['counts']['invalid']);
        $this->assertArrayNotHasKey('raw', $batch->summary);
        $this->assertArrayNotHasKey('path', $batch->summary);
        $this->assertDatabaseCount('assets', 0);
    }

    public function test_mixed_asset_preview_preserves_valid_categories_and_uncapped_invalid_count(): void
    {
        [, $project, $actor] = $this->context();
        Asset::factory()->for($project, 'project')->create([
            'key' => 'UNCHANGED', 'name' => 'Unchanged', 'type' => AssetType::Application,
            'description' => null, 'owner_name' => null, 'owner_email' => null, 'is_active' => true,
        ]);
        Asset::factory()->for($project, 'project')->create([
            'key' => 'CHANGED', 'name' => 'Before', 'type' => AssetType::Application,
            'description' => null, 'owner_name' => null, 'owner_email' => null, 'is_active' => true,
        ]);
        $rows = [
            'NEW,New,application,,,,true',
            'CHANGED,After,application,,,,true',
            'UNCHANGED,Unchanged,application,,,,true',
        ];
        foreach (range(1, 205) as $index) {
            $rows[] = "INVALID-{$index},Invalid {$index},invalid-type,,,,true";
        }
        $contents = "key,name,type,description,owner_name,owner_email,active\n".implode("\n", $rows)."\n";

        $batch = app(RegisterImportPreviewer::class)->preview(
            $project,
            RegisterImportKind::Assets,
            $this->upload('assets.csv', $contents),
            $actor,
        );

        $this->assertSame(RegisterImportStatus::Rejected, $batch->status);
        $this->assertEquals(['new' => 1, 'changed' => 1, 'unchanged' => 1, 'invalid' => 205], $batch->summary['counts']);
        $this->assertSame(['NEW', 'CHANGED', 'UNCHANGED'], array_column($batch->payload, 'key'));
        $this->assertCount(200, $batch->summary['rows']);
        $this->assertSame(['invalid'], array_values(array_unique(array_column($batch->summary['rows'], 'category'))));
        $this->assertArrayNotHasKey('values', $batch->summary['rows'][0]);
    }

    public function test_asset_preview_prioritizes_a_late_error_after_two_hundred_valid_rows(): void
    {
        [, $project, $actor] = $this->context();
        $rows = [];
        foreach (range(1, 205) as $index) {
            $rows[] = "ASSET-{$index},Asset {$index},application,,,,true";
        }
        $rows[] = 'INVALID-LATE,Invalid late,invalid-type,,,,true';
        $contents = "key,name,type,description,owner_name,owner_email,active\n".implode("\n", $rows)."\n";

        $batch = app(RegisterImportPreviewer::class)->preview(
            $project,
            RegisterImportKind::Assets,
            $this->upload('assets.csv', $contents),
            $actor,
        );

        $this->assertSame(RegisterImportStatus::Rejected, $batch->status);
        $this->assertEquals(['new' => 205, 'changed' => 0, 'unchanged' => 0, 'invalid' => 1], $batch->summary['counts']);
        $this->assertCount(200, $batch->summary['rows']);
        $this->assertSame(207, $batch->summary['rows'][0]['line']);
        $this->assertSame('type', $batch->summary['rows'][0]['field']);
        $this->assertSame('invalid', $batch->summary['rows'][0]['category']);
        $this->assertSame('invalid_row', $batch->summary['rows'][0]['code']);
        $this->assertArrayNotHasKey('values', $batch->summary['rows'][0]);
    }

    public function test_asset_preview_recognizes_changed_and_unchanged_rows(): void
    {
        [, $project, $actor] = $this->context();
        Asset::factory()->for($project, 'project')->create([
            'key' => 'CRM', 'name' => 'CRM', 'type' => AssetType::Application,
            'description' => null, 'owner_name' => null, 'owner_email' => null, 'is_active' => true,
        ]);
        Asset::factory()->for($project, 'project')->create([
            'key' => 'SERVER', 'name' => 'Old server', 'type' => AssetType::ItSystem,
            'description' => null, 'owner_name' => null, 'owner_email' => null, 'is_active' => true,
        ]);
        $contents = implode("\n", [
            'key,name,type,description,owner_name,owner_email,active',
            'crm,CRM,application,,,,true',
            'server,New server,it_system,,,,true',
            'BACKUP,Backup,service,,,,true',
        ])."\n";

        $batch = app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::Assets, $this->upload('assets.csv', $contents), $actor);

        $this->assertEquals(['new' => 1, 'changed' => 1, 'unchanged' => 1, 'invalid' => 0], $batch->summary['counts']);
        $this->assertSame(['unchanged', 'changed', 'new'], array_column($batch->summary['rows'], 'category'));
        $this->assertDatabaseCount('assets', 2);
    }

    public function test_dependency_preview_rejects_unknown_inactive_forbidden_duplicate_and_cyclic_edges_without_writes(): void
    {
        [, $project, $actor] = $this->context();
        $first = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1', 'is_active' => true]);
        $second = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2', 'is_active' => true]);
        $inactive = Asset::factory()->for($project, 'project')->create(['key' => 'INACTIVE', 'is_active' => false]);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'A1', 'is_active' => true]);
        DependencyEdge::factory()->for($project, 'project')->create([
            'source_process_id' => $first->id,
            'source_asset_id' => null,
            'target_process_id' => $second->id,
            'target_asset_id' => null,
            'is_active' => true,
        ]);

        $cases = [
            ['process,P1,asset,MISSING,critical,,true', 'unknown_endpoint'],
            ['process,P1,asset,INACTIVE,critical,,true', 'inactive_endpoint'],
            ['asset,A1,process,P1,critical,,true', 'invalid_row'],
            ["process,P1,asset,A1,critical,,true\nprocess,P1,asset,A1,supporting,,true", 'invalid_row'],
            ['process,P2,process,P1,critical,,true', 'cycle'],
        ];

        foreach ($cases as [$rows, $expectedCode]) {
            $contents = "source_type,source_key,target_type,target_key,importance,reason,active\n{$rows}\n";
            $batch = app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::Dependencies, $this->upload('dependencies.csv', $contents), $actor);
            $this->assertSame(RegisterImportStatus::Rejected, $batch->status);
            $this->assertContains($expectedCode, array_column($batch->summary['rows'], 'code'));
        }

        $this->assertDatabaseCount('dependency_edges', 1);
    }

    public function test_dependency_preview_checks_valid_cycle_candidates_when_another_row_has_an_invalid_endpoint(): void
    {
        [, $project, $actor] = $this->context();
        $first = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1', 'is_active' => true]);
        $second = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2', 'is_active' => true]);
        DependencyEdge::factory()->for($project, 'project')->create([
            'source_process_id' => $first->id, 'source_asset_id' => null,
            'target_process_id' => $second->id, 'target_asset_id' => null,
            'is_active' => true,
        ]);
        $contents = implode("\n", [
            'source_type,source_key,target_type,target_key,importance,reason,active',
            'process,P1,asset,MISSING,critical,,true',
            'process,P2,process,P1,critical,,true',
        ])."\n";

        $batch = app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::Dependencies, $this->upload('dependencies.csv', $contents), $actor);

        $this->assertSame(RegisterImportStatus::Rejected, $batch->status);
        $this->assertSame(['unknown_endpoint', 'cycle'], array_column($batch->summary['rows'], 'code'));
    }

    public function test_dependency_preview_marks_only_candidate_rows_that_participate_in_a_cycle(): void
    {
        [, $project, $actor] = $this->context();
        $first = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1', 'is_active' => true]);
        $second = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2', 'is_active' => true]);
        Asset::factory()->for($project, 'project')->create(['key' => 'A1', 'is_active' => true]);
        DependencyEdge::factory()->for($project, 'project')->create([
            'source_process_id' => $first->id, 'source_asset_id' => null,
            'target_process_id' => $second->id, 'target_asset_id' => null,
            'is_active' => true,
        ]);
        $contents = implode("\n", [
            'source_type,source_key,target_type,target_key,importance,reason,active',
            'process,P2,process,P1,critical,,true',
            'process,P2,asset,A1,supporting,,true',
        ])."\n";

        $batch = app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::Dependencies, $this->upload('dependencies.csv', $contents), $actor);

        $this->assertSame(RegisterImportStatus::Rejected, $batch->status);
        $this->assertSame(['invalid', 'new'], array_column($batch->summary['rows'], 'category'));
        $this->assertSame(['cycle', null], array_column($batch->summary['rows'], 'code'));
    }

    public function test_dependency_preview_keeps_active_edges_with_inactive_endpoints_in_cycle_simulation(): void
    {
        [, $project, $actor] = $this->context();
        $first = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1', 'is_active' => true]);
        $inactive = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2', 'is_active' => false]);
        $third = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P3', 'is_active' => true]);
        DependencyEdge::factory()->for($project, 'project')->create([
            'source_process_id' => $first->id, 'source_asset_id' => null,
            'target_process_id' => $inactive->id, 'target_asset_id' => null,
            'is_active' => true,
        ]);
        DependencyEdge::factory()->for($project, 'project')->create([
            'source_process_id' => $inactive->id, 'source_asset_id' => null,
            'target_process_id' => $third->id, 'target_asset_id' => null,
            'is_active' => true,
        ]);
        $contents = implode("\n", [
            'source_type,source_key,target_type,target_key,importance,reason,active',
            'process,P3,process,P1,critical,,true',
        ])."\n";

        $batch = app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::Dependencies, $this->upload('dependencies.csv', $contents), $actor);

        $this->assertSame(RegisterImportStatus::Rejected, $batch->status);
        $this->assertEquals(['new' => 0, 'changed' => 0, 'unchanged' => 0, 'invalid' => 1], $batch->summary['counts']);
        $this->assertSame('cycle', $batch->summary['rows'][0]['code']);
    }

    /** @return array{Organization, IsmsProject, User} */
    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();

        return [$customer, $project, $actor];
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'register-import-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
