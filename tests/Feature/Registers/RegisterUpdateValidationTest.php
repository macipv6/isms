<?php

namespace Tests\Feature\Registers;

use App\Enums\AssetType;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterUpdateValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_requests_reject_parseable_non_iso_concurrency_tokens(): void
    {
        [$organization, $project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create();
        $asset = Asset::factory()->for($project, 'project')->create();

        $this->actingAs($actor)
            ->put($this->processUrl($organization, $project, $process), $this->processPayload(['updated_at' => 'September 10, 2026 12:34:56 UTC']))
            ->assertSessionHasErrors('updated_at');
        $this->actingAs($actor)
            ->put($this->assetUrl($organization, $project, $asset), $this->assetPayload(['updated_at' => 'September 10, 2026 12:34:56 UTC']))
            ->assertSessionHasErrors('updated_at');
    }

    public function test_update_requests_accept_application_emitted_iso_concurrency_tokens(): void
    {
        [$organization, $project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create();
        $asset = Asset::factory()->for($project, 'project')->create();

        $this->actingAs($actor)
            ->put($this->processUrl($organization, $project, $process), $this->processPayload(['updated_at' => $process->updated_at->toIso8601String()]))
            ->assertRedirect();
        $this->actingAs($actor)
            ->put($this->assetUrl($organization, $project, $asset), $this->assetPayload(['updated_at' => $asset->updated_at->toIso8601String()]))
            ->assertRedirect();
    }

    private function context(): array
    {
        $organization = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($organization)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create(['role' => UserRole::Consultant]);

        return [$organization, $project, $actor];
    }

    private function processUrl(Organization $organization, IsmsProject $project, BusinessProcess $process): string
    {
        return "/organizations/{$organization->id}/projects/{$project->id}/processes/{$process->id}";
    }

    private function assetUrl(Organization $organization, IsmsProject $project, Asset $asset): string
    {
        return "/organizations/{$organization->id}/projects/{$project->id}/assets/{$asset->id}";
    }

    private function processPayload(array $override = []): array
    {
        return [...['name' => 'Sales', 'description' => null, 'owner_name' => null, 'owner_email' => null], ...$override];
    }

    private function assetPayload(array $override = []): array
    {
        return [...['name' => 'CRM', 'type' => AssetType::Application->value, 'description' => null, 'owner_name' => null, 'owner_email' => null], ...$override];
    }
}
