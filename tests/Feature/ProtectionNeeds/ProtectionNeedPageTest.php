<?php

namespace Tests\Feature\ProtectionNeeds;

use App\Enums\ProjectStatus;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProtectionNeed;
use App\Models\ProtectionNeedDowngradeRequest;
use App\Models\RegisterImportBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProtectionNeedPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_exposes_bounded_cia_rows_sources_pending_downgrade_and_named_actions(): void
    {
        [$customer, $project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC-1', 'name' => 'Auftrag']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'ASSET-1', 'name' => 'Dienst']);
        $edge = DependencyEdge::query()->create([
            'project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id,
            'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id,
        ]);
        $need = ProtectionNeed::factory()->for($project, 'project')->create([
            'business_process_id' => null, 'asset_id' => $asset->id, 'objective' => 'confidentiality',
            'direct_level' => 'normal', 'direct_rationale' => 'Dokumentierte Bewertung', 'inherited_level' => 'high',
            'effective_level' => 'high', 'status' => 'assessed', 'needs_review' => true, 'lock_version' => 3,
        ]);
        $need->sources()->create([
            'project_id' => $project->id, 'origin_process_id' => $process->id,
            'origin_level' => 'high', 'first_edge_id' => $edge->id,
        ]);
        $downgrade = ProtectionNeedDowngradeRequest::query()->create([
            'project_id' => $project->id, 'protection_need_id' => $need->id,
            'inherited_level' => 'high', 'requested_level' => 'normal', 'rationale' => 'Antrag',
            'requested_by' => $actor->id, 'status' => 'pending',
        ]);
        for ($index = 0; $index < 25; $index++) {
            $extra = BusinessProcess::factory()->for($project, 'project')->create();
            ProtectionNeed::factory()->for($project, 'project')->create(['business_process_id' => $extra->id]);
        }

        $base = $this->base($customer, $project);
        $this->actingAs($actor)->get($base.'/protection-needs?target_type=asset&state=active')
            ->assertOk()->assertInertia(fn (Assert $page): Assert => $page
                ->component('protection-needs/Index')
                ->where('organization.id', $customer->id)
                ->where('filters.targetType', 'asset')
                ->where('capabilities.write', true)
                ->where('capabilities.import', true)
                ->where('actions.previewImport', $base.'/imports/protection_needs/preview')
                ->where('needs.meta.perPage', 25)
                ->has('needs.data', 1)
                ->where('needs.data.0.target.key', 'ASSET-1')
                ->where('needs.data.0.directLevel', 'normal')
                ->where('needs.data.0.inheritedLevel', 'high')
                ->where('needs.data.0.effectiveLevel', 'high')
                ->where('needs.data.0.directRationale', 'Dokumentierte Bewertung')
                ->where('needs.data.0.status', 'assessed')
                ->where('needs.data.0.needsReview', true)
                ->where('needs.data.0.lockVersion', 3)
                ->where('needs.data.0.expectedVersions.confidentiality', 3)
                ->where('needs.data.0.expectedVersions.integrity', 0)
                ->where('needs.data.0.expectedVersions.availability', 0)
                ->where('needs.data.0.actions.rate', $base.'/protection-needs/targets/asset/'.$asset->id)
                ->where('needs.data.0.actions.approve', $base.'/protection-needs/targets/asset/'.$asset->id.'/approve')
                ->where('needs.data.0.actions.requestDowngrade', $base.'/protection-needs/'.$need->id.'/downgrades')
                ->where('needs.data.0.downgrade.id', $downgrade->id)
                ->where('needs.data.0.downgrade.status', 'pending')
                ->where('needs.data.0.downgrade.requestedLevel', 'normal')
                ->where('needs.data.0.downgrade.canDecideInternal', false)
                ->where('needs.data.0.downgrade.actions.decide', $base.'/downgrades/'.$downgrade->id.'/decision')
                ->where('needs.data.0.sources.0.source.key', 'PROC-1')
                ->where('needs.data.0.sources.0.path.0', 'PROC-1')
                ->where('needs.data.0.sources.0.path.1', 'ASSET-1')
                ->missing('needs.data.0.sources.0.first_edge_id')
                ->missing('needs.data.0.downgrade.rationale'));

        $this->actingAs($actor)->get($base.'/protection-needs?state=active')
            ->assertOk()->assertInertia(fn (Assert $page): Assert => $page
                ->has('needs.data', 25)
                ->where('needs.meta.perPage', 25)
                ->where('needs.meta.lastPage', 2)
                ->where('needs.links.next', fn (mixed $link): bool => is_string($link) && str_contains($link, 'state=active')));
    }

    public function test_historical_page_is_readable_but_has_no_write_or_import_actions(): void
    {
        [$customer, $project, $actor] = $this->context(ProjectStatus::Completed);
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'HISTORY']);
        ProtectionNeed::factory()->for($project, 'project')->create(['business_process_id' => $process->id]);

        $this->actingAs($actor)->get($this->base($customer, $project).'/protection-needs')
            ->assertOk()->assertInertia(fn (Assert $page): Assert => $page
                ->component('protection-needs/Index')
                ->has('needs.data', 1)
                ->where('capabilities.write', false)
                ->where('capabilities.import', false)
                ->missing('needs.data.0.actions.rate')
                ->missing('needs.data.0.actions.approve')
                ->missing('needs.data.0.actions.requestDowngrade'));
    }

    public function test_import_preview_is_bounded_and_redacted_with_downgrade_marker(): void
    {
        [$customer, $project, $actor] = $this->context();
        $rows = array_map(fn (int $line): array => [
            'line' => $line, 'category' => 'changed', 'requires_downgrade_approval' => true,
            'values' => ['object_type' => 'asset', 'object_key' => 'ASSET-1', 'confidentiality_rationale' => 'PRIVATE'],
        ], range(2, 251));
        $batch = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            'kind' => 'protection_needs', 'sha256' => str_repeat('a', 64),
            'payload' => [['private' => 'SECRET']],
            'summary' => ['counts' => ['new' => 0, 'changed' => 250, 'unchanged' => 0, 'invalid' => 0], 'rows' => $rows, 'state_fingerprint' => 'FINGERPRINT'],
        ]);

        $response = $this->actingAs($actor)->get($this->base($customer, $project).'/protection-needs?batch='.$batch->id);
        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->has('importPreview.rows', 200)
            ->where('importPreview.kind', 'protection_needs')
            ->where('importPreview.rows.0.requires_downgrade_approval', true)
            ->where('importPreview.rows.0.reference', 'asset:ASSET-1')
            ->where('importPreview.canConfirm', true)
            ->missing('importPreview.rows.0.values')
            ->missing('importPreview.sha256')
            ->missing('importPreview.state_fingerprint'));
        $this->assertStringNotContainsString('PRIVATE', $response->getContent());
        $this->assertStringNotContainsString('SECRET', $response->getContent());
        $this->assertStringNotContainsString('FINGERPRINT', $response->getContent());
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
}
