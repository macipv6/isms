<?php

namespace Tests\Feature\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Enums\ProtectionLevel;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\EvidenceFile;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProtectionNeed;
use App\Models\User;
use App\Services\Dependencies\DependencyService;
use App\Services\ProtectionNeeds\DowngradeWorkflow;
use App\Services\ProtectionNeeds\ProtectionNeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DowngradeWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_inherited_high_stays_effective_until_a_distinct_internal_person_approves(): void
    {
        [$project, $actor, $second, $need] = $this->context();
        $workflow = app(DowngradeWorkflow::class);
        $request = $workflow->request($need, ProtectionLevel::Normal, 'Ersatzverfahren vorhanden', $actor);
        $this->assertSame('high', $need->fresh()->effective_level->value);
        $this->assertValidation(fn () => $workflow->decideInternal($request, true, 'Prüfung erfolgt', $actor));
        $workflow->decideInternal($request, true, 'Prüfung erfolgt', $second);
        $this->assertSame('normal', $need->fresh()->effective_level->value);
        $this->assertSame('approved', $request->fresh()->status->value);
    }

    public function test_external_decision_requires_documentation_and_same_project_verified_evidence(): void
    {
        [$project, $actor, , $need] = $this->context();
        $workflow = app(DowngradeWorkflow::class);
        $request = $workflow->request($need, ProtectionLevel::Normal, 'Ersatzverfahren vorhanden', $actor);
        $this->assertValidation(fn () => $workflow->recordExternal($request, true, '', 'Leitung', '2026-09-29', 'Freigabe', null, $actor));
        $foreign = IsmsProject::factory()->for(Organization::factory()->state(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $evidence = EvidenceFile::factory()->for($foreign, 'project')->create(['status' => 'verified']);
        $this->assertValidation(fn () => $workflow->recordExternal($request, true, 'A. Beispiel', 'Leitung', '2026-09-29', 'Freigabe', $evidence, $actor));
        $workflow->recordExternal($request, true, 'A. Beispiel', 'Leitung', '2026-09-29', 'Freigabe', null, $actor);
        $this->assertSame('external', $request->fresh()->decision_channel);
    }

    public function test_graph_change_invalidates_pending_decision_even_when_maximum_is_unchanged(): void
    {
        [$project, $actor, , $need] = $this->context();
        $request = app(DowngradeWorkflow::class)->request($need, ProtectionLevel::Normal, 'Ersatzverfahren', $actor);
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
        $asset = Asset::query()->findOrFail($need->asset_id);
        app(DependencyService::class)->create($project, ['source_type' => 'process', 'source_key' => 'P2', 'target_type' => 'asset', 'target_key' => $asset->key, 'importance' => 'supporting', 'reason' => null], $actor);
        $this->assertSame('invalidated', $request->fresh()->status->value);
    }

    private function context(): array
    {
        $project = IsmsProject::factory()->for(Organization::factory()->state(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $second = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $process = BusinessProcess::factory()->for($project, 'project')->create();
        $asset = Asset::factory()->for($project, 'project')->create();
        DependencyEdge::query()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id, 'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id]);
        $service = app(ProtectionNeedService::class);
        $service->rateTarget($project, DependencyNode::process($process), [
            'confidentiality' => ['level' => 'high', 'rationale' => 'Wichtig'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Prüfbar'],
            'availability' => ['level' => 'normal', 'rationale' => 'Ersatz'],
        ], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
        $assetNeed = ProtectionNeed::query()->where('asset_id', $asset->id)->where('objective', 'confidentiality')->firstOrFail();
        $service->rateTarget($project, DependencyNode::asset($asset), ['confidentiality' => ['level' => 'normal', 'rationale' => 'Kompensiert']], $actor, [
            'confidentiality' => $assetNeed->lock_version,
            'integrity' => ProtectionNeed::query()->where('asset_id', $asset->id)->where('objective', 'integrity')->value('lock_version'),
            'availability' => ProtectionNeed::query()->where('asset_id', $asset->id)->where('objective', 'availability')->value('lock_version'),
        ]);

        return [$project, $actor, $second, $assetNeed->fresh()];
    }

    private function assertValidation(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Validation exception expected.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }
}
