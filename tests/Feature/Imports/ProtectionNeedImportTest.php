<?php

namespace Tests\Feature\Imports;

use App\Data\Dependencies\DependencyNode;
use App\Enums\ProtectionLevel;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProtectionNeed;
use App\Models\ProtectionNeedDowngradeRequest;
use App\Models\RegisterImportBatch;
use App\Models\User;
use App\Services\Imports\RegisterImportConfirmer;
use App\Services\Imports\RegisterImportPreviewer;
use App\Services\ProtectionNeeds\DowngradeWorkflow;
use App\Services\ProtectionNeeds\ProtectionNeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProtectionNeedImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_and_confirm_only_direct_ratings_for_existing_active_typed_targets(): void
    {
        [$project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'SAME']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'SAME']);
        app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::process($process), [
            'confidentiality' => ['level' => 'high', 'rationale' => 'Before'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Stable'],
            'availability' => ['level' => 'normal', 'rationale' => 'Stable'],
        ], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
        $auditBefore = AuditEvent::query()->count();

        $batch = $this->preview($project, $actor, [
            'PROCESS,same,normal,After,normal,Stable,normal,Stable',
            'asset,same,very_high,Asset secret,normal,Asset checked,high,Asset spare',
        ], 'OBJECT_TYPE,OBJECT_KEY,CONFIDENTIALITY,CONFIDENTIALITY_RATIONALE,INTEGRITY,INTEGRITY_RATIONALE,AVAILABILITY,AVAILABILITY_RATIONALE');

        $this->assertSame(RegisterImportStatus::Pending, $batch->status);
        $this->assertEquals(['new' => 1, 'changed' => 1, 'unchanged' => 0, 'invalid' => 0], $batch->summary['counts']);
        $this->assertSame('SAME', $batch->payload[0]['object_key']);
        $this->assertSame($auditBefore + 1, AuditEvent::query()->count());

        app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $this->assertSame(RegisterImportStatus::Applied, $batch->fresh()->status);
        $this->assertDatabaseHas('protection_needs', ['business_process_id' => $process->id, 'objective' => 'confidentiality', 'direct_level' => 'normal', 'direct_rationale' => 'After']);
        $this->assertDatabaseHas('protection_needs', ['asset_id' => $asset->id, 'objective' => 'confidentiality', 'direct_level' => 'very_high', 'direct_rationale' => 'Asset secret']);
        $this->assertSame(6, ProtectionNeed::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('event_type', 'protection_needs_import.applied')->count());
        $this->assertSame($auditBefore + 2, AuditEvent::query()->count());
        $audit = AuditEvent::query()->get()->toJson();
        $this->assertStringNotContainsString('Asset secret', $audit);
        $this->assertStringNotContainsString('After', $audit);
    }

    public function test_preview_rejects_duplicate_typed_key_unknown_and_inactive_targets(): void
    {
        [$project, $actor] = $this->context();
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        Asset::factory()->for($project, 'project')->create(['key' => 'A1', 'is_active' => false]);
        $batch = $this->preview($project, $actor, [
            'process,p1,high,First,normal,Check,normal,Spare',
            'process,P1,high,Second,normal,Check,normal,Spare',
            'asset,A1,normal,One,normal,Two,normal,Three',
            'asset,A2,normal,One,normal,Two,normal,Three',
        ]);

        $this->assertSame(RegisterImportStatus::Rejected, $batch->status);
        $this->assertSame(3, $batch->summary['counts']['invalid']);
        $this->assertSame(0, ProtectionNeed::query()->count());
    }

    public function test_preview_marks_inherited_downgrade_without_creating_an_approval(): void
    {
        [$project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        DependencyEdge::query()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id, 'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id]);
        app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::process($process), [
            'confidentiality' => ['level' => 'very_high', 'rationale' => 'Source'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Checked'],
            'availability' => ['level' => 'normal', 'rationale' => 'Spare'],
        ], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);

        $batch = $this->preview($project, $actor, ['asset,A1,normal,Local,normal,Checked,normal,Spare']);

        $this->assertTrue($batch->summary['rows'][0]['requires_downgrade_approval']);
        $this->assertDatabaseCount('protection_need_downgrade_requests', 0);
        app(RegisterImportConfirmer::class)->confirm($batch, $actor);
        $this->assertDatabaseCount('protection_need_downgrade_requests', 0);
        $this->assertDatabaseHas('protection_needs', ['asset_id' => $asset->id, 'objective' => 'confidentiality', 'direct_level' => 'normal', 'inherited_level' => 'very_high', 'effective_level' => 'very_high']);
    }

    public function test_preview_marks_downgrade_created_by_another_row_in_the_same_file(): void
    {
        [$project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        DependencyEdge::query()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id, 'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id]);

        $batch = $this->preview($project, $actor, [
            'asset,A1,normal,Local,normal,Checked,normal,Spare',
            'process,P1,very_high,Source,normal,Checked,normal,Spare',
        ]);

        $this->assertTrue($batch->summary['rows'][0]['requires_downgrade_approval']);
    }

    public function test_preview_does_not_mark_downgrade_when_same_file_lowers_the_source(): void
    {
        [$project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        DependencyEdge::query()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id, 'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id]);
        app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::process($process), [
            'confidentiality' => ['level' => 'very_high', 'rationale' => 'Old source'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Checked'],
            'availability' => ['level' => 'normal', 'rationale' => 'Spare'],
        ], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);

        $batch = $this->preview($project, $actor, [
            'asset,A1,normal,Local,normal,Checked,normal,Spare',
            'process,P1,normal,Lower source,normal,Checked,normal,Spare',
        ]);

        $this->assertFalse($batch->summary['rows'][0]['requires_downgrade_approval']);
    }

    public function test_preview_marks_prior_approval_invalidated_by_a_new_tied_source(): void
    {
        [$project, $actor] = $this->context();
        $second = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $first = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $secondProcess = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P2']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        foreach ([$first, $secondProcess] as $process) {
            DependencyEdge::query()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id, 'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id]);
        }
        app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::process($first), [
            'confidentiality' => ['level' => 'high', 'rationale' => 'First source'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Checked'],
            'availability' => ['level' => 'normal', 'rationale' => 'Spare'],
        ], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
        $versions = ProtectionNeed::query()->where('asset_id', $asset->id)->pluck('lock_version', 'objective')->all();
        app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::asset($asset), [
            'confidentiality' => ['level' => 'normal', 'rationale' => 'Local'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Checked'],
            'availability' => ['level' => 'normal', 'rationale' => 'Spare'],
        ], $actor, $versions);
        $need = ProtectionNeed::query()->where('asset_id', $asset->id)->where('objective', 'confidentiality')->firstOrFail();
        $workflow = app(DowngradeWorkflow::class);
        $approval = $workflow->request($need, ProtectionLevel::Normal, 'Compensating control', $actor);
        $workflow->decideInternal($approval, true, 'Reviewed', $second);
        $this->assertSame('approved', $approval->fresh()->status->value);

        $batch = $this->preview($project, $actor, [
            'asset,A1,normal,Local,normal,Checked,normal,Spare',
            'process,P2,high,Second source,normal,Checked,normal,Spare',
        ]);

        $this->assertTrue($batch->summary['rows'][0]['requires_downgrade_approval']);
        app(RegisterImportConfirmer::class)->confirm($batch, $actor);
        $this->assertSame('invalidated', $approval->fresh()->status->value);
    }

    public function test_preview_marks_downgrade_when_only_another_objective_changes_on_an_approved_target(): void
    {
        [$project, $actor, $asset, $approval] = $this->approvedDowngradeContext();

        $batch = $this->preview($project, $actor, [
            'asset,A1,normal,Local,high,New integrity,normal,Spare',
        ]);

        $this->assertTrue($batch->summary['rows'][0]['requires_downgrade_approval']);
        $this->assertSame('approved', $approval->fresh()->status->value);
        $this->assertDatabaseHas('protection_needs', ['asset_id' => $asset->id, 'objective' => 'confidentiality', 'effective_level' => 'normal']);

        app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $this->assertSame('invalidated', $approval->fresh()->status->value);
        $this->assertDatabaseHas('protection_needs', ['asset_id' => $asset->id, 'objective' => 'confidentiality', 'direct_level' => 'normal', 'effective_level' => 'high']);
    }

    public function test_preview_projects_target_wide_invalidation_to_an_earlier_downstream_row(): void
    {
        [$project, $actor, $asset, $approval] = $this->approvedDowngradeContext();
        $downstream = Asset::factory()->for($project, 'project')->create(['key' => 'A2']);
        DependencyEdge::query()->create(['project_id' => $project->id, 'source_asset_id' => $asset->id, 'target_asset_id' => $downstream->id, 'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id]);
        app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::asset($downstream), [
            'confidentiality' => ['level' => 'normal', 'rationale' => 'Downstream local'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Checked'],
            'availability' => ['level' => 'normal', 'rationale' => 'Spare'],
        ], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);

        $batch = $this->preview($project, $actor, [
            'asset,A2,normal,Downstream local,normal,Checked,normal,Spare',
            'asset,A1,normal,Local,normal,New integrity rationale,normal,Spare',
        ]);

        $this->assertTrue($batch->summary['rows'][0]['requires_downgrade_approval']);
        $this->assertTrue($batch->summary['rows'][1]['requires_downgrade_approval']);
        $this->assertSame('approved', $approval->fresh()->status->value);

        app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $this->assertSame('invalidated', $approval->fresh()->status->value);
        $this->assertDatabaseHas('protection_needs', ['asset_id' => $downstream->id, 'objective' => 'confidentiality', 'direct_level' => 'normal', 'inherited_level' => 'high', 'effective_level' => 'high']);
    }

    public function test_nonexact_headers_and_formula_cells_are_rejected(): void
    {
        [$project, $actor] = $this->context();
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $row = 'process,P1,high,Reason,normal,Checked,normal,Spare';
        $wrongHeader = $this->preview($project, $actor, [$row], 'object_type,object_key,confidentiality,confidentiality_rationale,integrity,integrity_rationale,availability,extra');
        $formula = $this->preview($project, $actor, ['process,P1,high,=HYPERLINK(1),normal,Checked,normal,Spare']);

        $this->assertSame(RegisterImportStatus::Rejected, $wrongHeader->status);
        $this->assertSame(RegisterImportStatus::Rejected, $formula->status);
    }

    public function test_preview_rejection_and_expiry_have_redacted_protection_need_audit_events(): void
    {
        [$project, $actor] = $this->context();
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $pending = $this->preview($project, $actor, ['process,P1,high,Private rationale,normal,Checked,normal,Spare']);
        $this->preview($project, $actor, ['process,P1,high,=HYPERLINK(1),normal,Checked,normal,Spare']);
        $pending->update(['expires_at' => now('UTC')->subMinute()]);

        $this->artisan('register-imports:purge')->assertSuccessful();

        $this->assertSame(RegisterImportStatus::Expired, $pending->fresh()->status);
        $events = AuditEvent::query()->pluck('event_type')->all();
        $this->assertEqualsCanonicalizing([
            'protection_needs_import.previewed',
            'protection_needs_import.rejected',
            'protection_needs_import.expired',
        ], $events);
        $this->assertStringNotContainsString('Private rationale', AuditEvent::query()->get()->toJson());
    }

    public function test_protection_need_kind_retains_file_and_row_limits(): void
    {
        [$project, $actor] = $this->context();
        $header = 'object_type,object_key,confidentiality,confidentiality_rationale,integrity,integrity_rationale,availability,availability_rationale';
        $largePath = tempnam(sys_get_temp_dir(), 'needs-large-');
        file_put_contents($largePath, $header."\n".str_repeat('x', 5 * 1024 * 1024));
        $large = app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::ProtectionNeeds, new UploadedFile($largePath, 'large.csv', 'text/csv', null, true), $actor);
        $manyRows = array_fill(0, 10001, 'process,P1,normal,One,normal,Two,normal,Three');
        $tooMany = $this->preview($project, $actor, $manyRows);

        $this->assertSame(RegisterImportStatus::Rejected, $large->status);
        $this->assertSame(RegisterImportStatus::Rejected, $tooMany->status);
        $this->assertSame([], $large->payload);
        $this->assertCount(1, $tooMany->payload);
    }

    public function test_http_preview_and_confirmation_routes_accept_protection_need_kind(): void
    {
        [$project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $base = '/organizations/'.$project->organization_id.'/projects/'.$project->id;
        $contents = "object_type,object_key,confidentiality,confidentiality_rationale,integrity,integrity_rationale,availability,availability_rationale\nprocess,P1,high,Secret,normal,Checked,normal,Spare\n";

        $this->actingAs($actor)->post($base.'/imports/protection_needs/preview', ['file' => UploadedFile::fake()->createWithContent('needs.csv', $contents)])->assertRedirect();
        $batch = RegisterImportBatch::query()->sole();
        $this->assertSame(RegisterImportStatus::Pending, $batch->status);
        $this->actingAs($actor)->post($base.'/imports/'.$batch->id.'/confirm')->assertRedirect();
        $this->assertSame(RegisterImportStatus::Applied, $batch->fresh()->status);
        $this->assertDatabaseHas('protection_needs', ['business_process_id' => $process->id, 'objective' => 'confidentiality', 'direct_level' => 'high']);
    }

    /** @return array{IsmsProject, User} */
    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();

        return [$project, $actor];
    }

    /** @return array{IsmsProject, User, Asset, ProtectionNeedDowngradeRequest} */
    private function approvedDowngradeContext(): array
    {
        [$project, $actor] = $this->context();
        $second = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'P1']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'A1']);
        DependencyEdge::query()->create(['project_id' => $project->id, 'source_process_id' => $process->id, 'target_asset_id' => $asset->id, 'importance' => 'supporting', 'is_active' => true, 'created_by' => $actor->id]);
        $service = app(ProtectionNeedService::class);
        $service->rateTarget($project, DependencyNode::process($process), [
            'confidentiality' => ['level' => 'high', 'rationale' => 'Source'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Checked'],
            'availability' => ['level' => 'normal', 'rationale' => 'Spare'],
        ], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
        $versions = ProtectionNeed::query()->where('asset_id', $asset->id)->pluck('lock_version', 'objective')->all();
        $service->rateTarget($project, DependencyNode::asset($asset), [
            'confidentiality' => ['level' => 'normal', 'rationale' => 'Local'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Checked'],
            'availability' => ['level' => 'normal', 'rationale' => 'Spare'],
        ], $actor, $versions);
        $need = ProtectionNeed::query()->where('asset_id', $asset->id)->where('objective', 'confidentiality')->firstOrFail();
        $workflow = app(DowngradeWorkflow::class);
        $approval = $workflow->request($need, ProtectionLevel::Normal, 'Compensating control', $actor);
        $workflow->decideInternal($approval, true, 'Reviewed', $second);
        $versions = ProtectionNeed::query()->where('asset_id', $asset->id)->pluck('lock_version', 'objective')->all();
        $service->approveTarget($project, DependencyNode::asset($asset), $actor, $versions);

        return [$project, $actor, $asset, $approval];
    }

    /** @param list<string> $rows */
    private function preview(IsmsProject $project, User $actor, array $rows, string $header = 'object_type,object_key,confidentiality,confidentiality_rationale,integrity,integrity_rationale,availability,availability_rationale'): RegisterImportBatch
    {
        $path = tempnam(sys_get_temp_dir(), 'needs-import-');
        file_put_contents($path, $header."\n".implode("\n", $rows)."\n");

        return app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::ProtectionNeeds, new UploadedFile($path, 'needs.csv', 'text/csv', null, true), $actor);
    }
}
