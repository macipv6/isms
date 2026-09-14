<?php

namespace Tests\Feature\Imports;

use App\Enums\ProjectStatus;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegisterImportPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owned_preview_is_bounded_redacted_and_exposes_stable_post_actions(): void
    {
        [$customer, $project, $actor] = $this->context();
        $rows = array_map(fn (int $line): array => [
            'line' => $line, 'category' => $line === 2 ? 'invalid' : 'new', 'field' => $line === 2 ? 'owner_email' : null,
            'code' => $line === 2 ? 'invalid_row' : null,
            'values' => ['key' => 'P-'.$line, 'name' => 'SECRET-'.$line, 'owner_email' => 'secret'.$line.'@example.test'],
        ], range(2, 251));
        $batch = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            'sha256' => str_repeat('a', 64),
            'payload' => [['key' => 'PAYLOAD-SECRET', 'name' => 'Raw secret']],
            'summary' => ['counts' => ['new' => 249, 'changed' => 0, 'unchanged' => 0, 'invalid' => 1], 'rows' => $rows, 'state_fingerprint' => 'SECRET-FINGERPRINT'],
        ]);

        $this->actingAs($actor)->get($this->url($customer, $project, 'processes').'?batch='.$batch->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('importPreview.rows', 200)
                ->where('importPreview.id', $batch->id)
                ->where('importPreview.counts.invalid', 1)
                ->where('importPreview.canConfirm', false)
                ->where('importPreview.actions.preview', $this->base($customer, $project).'/imports/processes/preview')
                ->where('importPreview.actions.confirm', $this->base($customer, $project).'/imports/'.$batch->id.'/confirm')
                ->where('importPreview.rows.0.line', 2)
                ->where('importPreview.rows.0.field', 'owner_email')
                ->missing('importPreview.rows.0.values')
                ->missing('importPreview.payload')
                ->missing('importPreview.sha256')
                ->missing('importPreview.created_by')
                ->missing('importPreview.state_fingerprint'));

        $responseJson = $this->actingAs($actor)->get($this->url($customer, $project, 'processes').'?batch='.$batch->id)->getContent();
        $this->assertStringNotContainsString('PAYLOAD-SECRET', $responseJson);
        $this->assertStringNotContainsString('SECRET-FINGERPRINT', $responseJson);
        $this->assertStringNotContainsString('secret2@example.test', $responseJson);
    }

    public function test_other_user_cross_project_and_wrong_kind_batches_are_not_exposed(): void
    {
        [$customer, $project, $actor] = $this->context();
        $other = User::factory()->for($actor->organization)->create();
        $otherBatch = RegisterImportBatch::factory()->for($project, 'project')->for($other, 'creator')->create();
        $wrongKind = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create(['kind' => 'assets']);
        $foreignBatch = RegisterImportBatch::factory()->for(IsmsProject::factory()->for($customer)->create(), 'project')->for($actor, 'creator')->create();

        foreach ([$otherBatch, $wrongKind, $foreignBatch] as $batch) {
            $this->actingAs($actor)->get($this->url($customer, $project, 'processes').'?batch='.$batch->id)->assertNotFound();
        }
    }

    public function test_expired_and_read_only_previews_remain_visible_without_confirmation(): void
    {
        [$customer, $project, $actor] = $this->context(status: ProjectStatus::Archived);
        $batch = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            'expires_at' => now('UTC')->subMinute(),
            'summary' => ['counts' => ['new' => 1, 'changed' => 0, 'unchanged' => 0, 'invalid' => 0], 'rows' => []],
        ]);

        $this->actingAs($actor)->get($this->url($customer, $project, 'processes').'?batch='.$batch->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->where('importPreview.expired', true)
                ->where('importPreview.canConfirm', false)
                ->where('capabilities.import', false));
    }

    /** @return array{Organization, IsmsProject, User} */
    private function context(ProjectStatus $status = ProjectStatus::Draft): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create(['status' => $status]);
        $actor = User::factory()->for(Organization::factory()->create(['organization_type' => 'internal']))->create();

        return [$customer, $project, $actor];
    }

    private function base(Organization $organization, IsmsProject $project): string
    {
        return "/organizations/{$organization->id}/projects/{$project->id}";
    }

    private function url(Organization $organization, IsmsProject $project, string $register): string
    {
        return $this->base($organization, $project).'/'.$register;
    }
}
