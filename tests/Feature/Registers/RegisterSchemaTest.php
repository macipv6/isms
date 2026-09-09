<?php

namespace Tests\Feature\Registers;

use App\Enums\AssetType;
use App\Enums\DependencyImportance;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\RegisterImportBatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RegisterSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_tables_contain_the_required_fields(): void
    {
        $this->assertTrue(Schema::hasColumns('business_processes', [
            'id', 'project_id', 'key', 'name', 'description', 'owner_name', 'owner_email',
            'is_active', 'created_by', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('assets', [
            'id', 'project_id', 'key', 'name', 'type', 'description', 'owner_name', 'owner_email',
            'is_active', 'created_by', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('dependency_edges', [
            'id', 'project_id', 'source_process_id', 'source_asset_id', 'target_process_id',
            'target_asset_id', 'importance', 'reason', 'is_active', 'created_by', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('register_import_batches', [
            'id', 'project_id', 'kind', 'created_by', 'sha256', 'payload', 'summary', 'status',
            'expires_at', 'applied_at', 'created_at', 'updated_at',
        ]));
    }

    public function test_factories_persist_enum_backed_register_attributes(): void
    {
        $asset = Asset::factory()->create(['type' => AssetType::ItSystem]);
        $edge = DependencyEdge::factory()->create(['importance' => DependencyImportance::Critical]);
        $batch = RegisterImportBatch::factory()->create([
            'kind' => RegisterImportKind::Assets,
            'status' => RegisterImportStatus::Pending,
            'payload' => [['key' => 'ERP-1']],
            'summary' => ['new' => 1],
        ]);

        $this->assertSame(AssetType::ItSystem, $asset->fresh()?->type);
        $this->assertSame(DependencyImportance::Critical, $edge->fresh()?->importance);
        $this->assertSame(RegisterImportKind::Assets, $batch->fresh()?->kind);
        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()?->status);
        $this->assertSame([['key' => 'ERP-1']], $batch->fresh()?->payload);
        $this->assertSame(['new' => 1], $batch->fresh()?->summary);
        $this->assertInstanceOf(\Carbon\CarbonImmutable::class, $batch->fresh()?->expires_at);
    }

    public function test_register_key_is_unique_within_its_project_but_reusable_in_another_project(): void
    {
        $project = IsmsProject::factory()->create();
        BusinessProcess::factory()->for($project)->create(['key' => 'SALES.EU']);
        BusinessProcess::factory()->for(IsmsProject::factory()->create())->create(['key' => 'SALES.EU']);

        $this->expectException(QueryException::class);
        BusinessProcess::factory()->for($project)->create(['key' => 'SALES.EU']);
    }

    public function test_register_key_must_match_the_stable_uppercase_format(): void
    {
        $this->expectException(QueryException::class);
        Asset::factory()->create(['key' => 'sales.eu']);
    }

    public function test_dependency_endpoint_cannot_cross_project_boundary(): void
    {
        $first = IsmsProject::factory()->create();
        $second = IsmsProject::factory()->create();
        $source = BusinessProcess::factory()->for($first)->create();
        $target = Asset::factory()->for($second)->create();

        $this->expectException(QueryException::class);
        DependencyEdge::factory()->create([
            'project_id' => $first->id,
            'source_process_id' => $source->id,
            'target_asset_id' => $target->id,
        ]);
    }

    public function test_dependency_allows_only_the_specified_endpoint_shapes(): void
    {
        $project = IsmsProject::factory()->create();
        $sourceProcess = BusinessProcess::factory()->for($project)->create();
        $targetProcess = BusinessProcess::factory()->for($project)->create();
        $sourceAsset = Asset::factory()->for($project)->create();
        $targetAsset = Asset::factory()->for($project)->create();

        DependencyEdge::factory()->create([
            'project_id' => $project->id,
            'source_process_id' => $sourceProcess->id,
            'target_process_id' => $targetProcess->id,
            'target_asset_id' => null,
        ]);
        DependencyEdge::factory()->create([
            'project_id' => $project->id,
            'source_process_id' => $sourceProcess->id,
            'target_asset_id' => $targetAsset->id,
        ]);
        DependencyEdge::factory()->create([
            'project_id' => $project->id,
            'source_process_id' => null,
            'source_asset_id' => $sourceAsset->id,
            'target_asset_id' => $targetAsset->id,
        ]);

        $this->assertSame(3, DependencyEdge::query()->where('project_id', $project->id)->count());
    }

    public function test_dependency_rejects_asset_to_process_and_empty_or_multiple_endpoints(): void
    {
        $project = IsmsProject::factory()->create();
        $asset = Asset::factory()->for($project)->create();
        $process = BusinessProcess::factory()->for($project)->create();

        foreach ([
            ['source_process_id' => null, 'source_asset_id' => $asset->id, 'target_process_id' => $process->id, 'target_asset_id' => null],
            ['source_process_id' => null, 'source_asset_id' => null, 'target_process_id' => null, 'target_asset_id' => null],
            ['source_process_id' => $process->id, 'source_asset_id' => $asset->id, 'target_process_id' => null, 'target_asset_id' => $asset->id],
        ] as $endpoints) {
            try {
                DependencyEdge::factory()->create(['project_id' => $project->id, ...$endpoints]);
                $this->fail('The dependency shape check accepted an invalid endpoint combination.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_only_one_active_edge_of_each_endpoint_pair_is_allowed(): void
    {
        $project = IsmsProject::factory()->create();
        $source = BusinessProcess::factory()->for($project)->create();
        $target = Asset::factory()->for($project)->create();
        $attributes = [
            'project_id' => $project->id,
            'source_process_id' => $source->id,
            'target_asset_id' => $target->id,
        ];

        DependencyEdge::factory()->create($attributes);
        DependencyEdge::factory()->create([...$attributes, 'is_active' => false]);

        $this->expectException(QueryException::class);
        DependencyEdge::factory()->create($attributes);
    }
}
