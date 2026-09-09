<?php

namespace Tests\Feature\Registers;

use App\Enums\AssetType;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Registers\AssetService;
use App\Services\Registers\BusinessProcessService;
use App\Services\Registers\RegisterKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RegisterWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_and_asset_keys_are_normalized_and_project_local(): void
    {
        [, $project, $actor] = $this->context();
        $other = IsmsProject::factory()->create();
        $processes = app(BusinessProcessService::class);
        $assets = app(AssetService::class);

        $process = $processes->create($project, $this->processPayload(['key' => ' sales.eu ']), $actor);
        $asset = $assets->create($project, $this->assetPayload(['key' => ' crm-01 ']), $actor);
        $assets->create($other, $this->assetPayload(['key' => 'crm-01']), $actor);

        $this->assertSame('SALES.EU', $process->key);
        $this->assertSame('CRM-01', $asset->key);
        $this->expectValidation(fn () => $assets->create($project, $this->assetPayload(['key' => 'CRM-01']), $actor), 'key');
    }

    public function test_register_key_rejects_invalid_stable_keys(): void
    {
        foreach (['a', '-SALES', 'SALES/DE', str_repeat('A', 65)] as $key) {
            $this->expectValidation(fn () => RegisterKey::normalize($key), 'key');
        }
    }

    public function test_process_updates_preserve_key_and_reject_stale_writes(): void
    {
        [, $project, $actor] = $this->context();
        $service = app(BusinessProcessService::class);
        $process = $service->create($project, $this->processPayload(), $actor);
        $expected = $process->updated_at->toIso8601String();

        $updated = $service->update($process, $this->processPayload(['name' => 'Neu', 'key' => 'IGNORED']), $actor, $expected);
        $this->assertSame('PROCESS-01', $updated->key);
        $this->assertSame('Neu', $updated->name);
        $this->expectValidation(fn () => $service->update($updated, $this->processPayload(), $actor, '2000-01-01T00:00:00+00:00'), 'updated_at');
    }

    public function test_assets_enforce_type_and_field_limits(): void
    {
        [, $project, $actor] = $this->context();
        $service = app(AssetService::class);

        foreach ([
            ['name', str_repeat('a', 161)],
            ['description', str_repeat('a', 4001)],
            ['owner_name', str_repeat('a', 161)],
            ['owner_email', 'not-an-email'],
            ['type', 'device'],
        ] as [$field, $value]) {
            $this->expectValidation(fn () => $service->create($project, $this->assetPayload([$field => $value]), $actor), $field);
        }
    }

    public function test_status_changes_deactivate_reactivate_and_ignore_noop(): void
    {
        [, $project, $actor] = $this->context();
        $service = app(AssetService::class);
        $asset = $service->create($project, $this->assetPayload(), $actor);

        $this->assertFalse($service->changeStatus($asset, false, $actor)->is_active);
        $this->assertTrue($service->changeStatus($asset->fresh(), true, $actor)->is_active);
        $this->assertTrue($service->changeStatus($asset->fresh(), true, $actor)->is_active);
    }

    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $actor = User::factory()->for($internal)->create();

        return [$customer, $project, $actor];
    }

    private function processPayload(array $override = []): array
    {
        return [...['key' => 'PROCESS-01', 'name' => 'Prozess', 'description' => null, 'owner_name' => null, 'owner_email' => null, 'active' => true], ...$override];
    }

    private function assetPayload(array $override = []): array
    {
        return [...['key' => 'ASSET-01', 'name' => 'Asset', 'type' => AssetType::Application->value, 'description' => null, 'owner_name' => null, 'owner_email' => null, 'active' => true], ...$override];
    }

    private function expectValidation(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
