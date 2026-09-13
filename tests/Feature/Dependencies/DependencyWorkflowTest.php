<?php

namespace Tests\Feature\Dependencies;

use App\Enums\DependencyImportance;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Dependencies\DependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DependencyWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_allowed_shapes_are_created_and_an_inactive_pair_is_reactivated_without_new_history(): void
    {
        [$project, $actor] = $this->context();
        $firstProcess = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC-A']);
        $secondProcess = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC-B']);
        $firstAsset = Asset::factory()->for($project, 'project')->create(['key' => 'ASSET-A']);
        $secondAsset = Asset::factory()->for($project, 'project')->create(['key' => 'ASSET-B']);
        $service = app(DependencyService::class);

        $processProcess = $service->create($project, $this->payload('process', 'PROC-A', 'process', 'PROC-B'), $actor);
        $processAsset = $service->create($project, $this->payload('process', 'PROC-B', 'asset', 'ASSET-A'), $actor);
        $assetAsset = $service->create($project, $this->payload('asset', 'ASSET-A', 'asset', 'ASSET-B'), $actor);
        $service->changeStatus($assetAsset, false, $actor);
        $reactivated = $service->create($project, $this->payload('asset', 'ASSET-A', 'asset', 'ASSET-B', ['importance' => 'critical']), $actor);

        $this->assertSame($assetAsset->id, $reactivated->id);
        $this->assertTrue($reactivated->is_active);
        $this->assertSame(DependencyImportance::Critical, $reactivated->importance);
        $this->assertSame($firstProcess->id, $processProcess->source_process_id);
        $this->assertSame($secondProcess->id, $processAsset->source_process_id);
        $this->assertSame($firstAsset->id, $assetAsset->source_asset_id);
        $this->assertSame($secondAsset->id, $assetAsset->target_asset_id);
        $this->assertDatabaseCount('dependency_edges', 3);
    }

    public function test_invalid_shapes_duplicate_inactive_endpoint_and_cycles_are_rejected(): void
    {
        [$project, $actor] = $this->context();
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P3']);
        Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        Asset::factory()->for($project, 'project')->create(['key' => 'A2', 'is_active' => false]);
        $service = app(DependencyService::class);

        $this->expectValidation(fn () => $service->create($project, $this->payload('asset', 'A1', 'process', 'P1'), $actor), 'target_type');
        $this->expectValidation(fn () => $service->create($project, $this->payload('process', 'P1', 'process', 'P1'), $actor), 'target_key');
        $this->expectValidation(fn () => $service->create($project, $this->payload('process', 'P1', 'asset', 'A2'), $actor), 'target_key');

        $service->create($project, $this->payload('process', 'P1', 'process', 'P2'), $actor);
        $this->expectValidation(fn () => $service->create($project, $this->payload('process', 'P1', 'process', 'P2'), $actor), 'dependency');
        $service->create($project, $this->payload('process', 'P2', 'process', 'P3'), $actor);
        $this->expectValidation(fn () => $service->create($project, $this->payload('process', 'P3', 'process', 'P1'), $actor), 'dependencies');
    }

    public function test_metadata_updates_use_stale_write_protection_and_endpoints_cannot_be_edited(): void
    {
        [$project, $actor] = $this->context();
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        $service = app(DependencyService::class);
        $edge = $service->create($project, $this->payload('process', 'P1', 'asset', 'A1'), $actor);
        $expected = $edge->updated_at->toIso8601String();

        $this->travel(1)->seconds();
        $updated = $service->update($edge, ['importance' => 'critical', 'reason' => 'Needed'], $actor, $expected);

        $this->assertSame(DependencyImportance::Critical, $updated->importance);
        $this->assertSame('Needed', $updated->reason);
        $this->expectValidation(fn () => $service->update($updated, ['importance' => 'supporting', 'reason' => null], $actor, $expected), 'updated_at');

        $response = $this->actingAs($actor)->put($this->edgeUrl($project, $edge), [
            'importance' => 'supporting',
            'reason' => null,
            'updated_at' => $updated->updated_at->toIso8601String(),
            'source_type' => 'asset',
            'source_key' => 'A1',
        ]);
        $response->assertSessionHasErrors(['source_type', 'source_key']);
    }

    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create(['status' => 'draft']);
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();

        return [$project, $actor];
    }

    /** @param array<string, mixed> $overrides */
    private function payload(string $sourceType, string $sourceKey, string $targetType, string $targetKey, array $overrides = []): array
    {
        return [...[
            'source_type' => $sourceType,
            'source_key' => $sourceKey,
            'target_type' => $targetType,
            'target_key' => $targetKey,
            'importance' => 'supporting',
            'reason' => null,
        ], ...$overrides];
    }

    private function expectValidation(callable $callback, string $field): void
    {
        try {
            $callback();
            $this->fail("Expected validation error for {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function edgeUrl(IsmsProject $project, DependencyEdge $edge): string
    {
        return "/organizations/{$project->organization_id}/projects/{$project->id}/dependencies/{$edge->id}";
    }
}
