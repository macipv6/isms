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
        $this->assertSame(['new' => 1, 'changed' => 1, 'unchanged' => 1, 'invalid' => 0], $batch->summary['counts']);
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
        $this->assertLessThanOrEqual(200, $batch->summary['counts']['invalid']);
        $this->assertArrayNotHasKey('raw', $batch->summary);
        $this->assertArrayNotHasKey('path', $batch->summary);
        $this->assertDatabaseCount('assets', 0);
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

        $this->assertSame(['new' => 1, 'changed' => 1, 'unchanged' => 1, 'invalid' => 0], $batch->summary['counts']);
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
