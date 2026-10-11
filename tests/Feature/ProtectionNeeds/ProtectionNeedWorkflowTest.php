<?php

namespace Tests\Feature\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProtectionNeed;
use App\Models\User;
use App\Services\ProtectionNeeds\ProtectionNeedInitializer;
use App\Services\ProtectionNeeds\ProtectionNeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProtectionNeedWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_has_three_rows_and_direct_assessment_requires_rationales_and_versions(): void
    {
        [$project, $actor, $process] = $this->context();
        $node = DependencyNode::process($process);
        app(ProtectionNeedInitializer::class)->ensureTarget($node);
        $this->assertSame(3, ProtectionNeed::query()->where('project_id', $project->id)->count());
        $this->assertEqualsCanonicalizing(['confidentiality', 'integrity', 'availability'], ProtectionNeed::query()->get()->map(fn (ProtectionNeed $need): string => $need->objective->value)->all());
        $service = app(ProtectionNeedService::class);
        $versions = ProtectionNeed::query()->get()->mapWithKeys(fn (ProtectionNeed $need): array => [$need->objective->value => $need->lock_version])->all();
        $this->assertValidation(fn () => $service->rateTarget($project, $node, ['confidentiality' => ['level' => 'high', 'rationale' => '']], $actor, $versions));
        $ratings = [
            'confidentiality' => ['level' => 'high', 'rationale' => 'Sensible Daten'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Prüfbar'],
            'availability' => ['level' => 'normal', 'rationale' => 'Ersatzverfahren'],
        ];
        $service->rateTarget($project, $node, $ratings, $actor, $versions);
        $this->assertSame('assessed', ProtectionNeed::query()->first()->status->value);
        $this->assertValidation(fn () => $service->rateTarget($project, $node, $ratings, $actor, $versions));
        $currentVersions = ProtectionNeed::query()->get()->mapWithKeys(fn (ProtectionNeed $need): array => [$need->objective->value => $need->lock_version])->all();
        $service->approveTarget($project, $node, $actor, $currentVersions);
        $this->assertSame(3, ProtectionNeed::query()->where('status', 'approved')->count());
        $approvedVersions = ProtectionNeed::query()->get()->mapWithKeys(fn (ProtectionNeed $need): array => [$need->objective->value => $need->lock_version])->all();
        $service->rateTarget($project, $node, ['confidentiality' => ['level' => 'very_high', 'rationale' => 'Erhöht']], $actor, $approvedVersions);
        $this->assertSame(3, ProtectionNeed::query()->where('status', 'assessed')->where('needs_review', true)->count());
    }

    public function test_inactive_and_foreign_targets_and_read_only_projects_are_rejected(): void
    {
        [$project, $actor, $process] = $this->context();
        $service = app(ProtectionNeedService::class);
        $process->update(['is_active' => false]);
        $this->assertValidation(fn () => $service->rateTarget($project, DependencyNode::process($process), [], $actor, []));
        $process->update(['is_active' => true]);
        [$foreignProject, , $foreignProcess] = $this->context();
        $this->assertValidation(fn () => $service->rateTarget($project, DependencyNode::process($foreignProcess), [], $actor, []));
        $project->update(['status' => 'completed']);
        $this->assertValidation(fn () => $service->rateTarget($project, DependencyNode::process($process), [], $actor, []));
        $this->assertValidation(fn () => app(ProtectionNeedInitializer::class)->ensureTarget(DependencyNode::process($process)));
    }

    public function test_draft_cannot_be_approved_even_if_raw_values_are_complete(): void
    {
        [$project, $actor, $process] = $this->context();
        $node = DependencyNode::process($process);
        app(ProtectionNeedInitializer::class)->ensureTarget($node);
        DB::table('protection_needs')->where('business_process_id', $process->id)->update(['direct_level' => 'normal', 'direct_rationale' => 'Rohwert']);
        $this->assertValidation(fn () => app(ProtectionNeedService::class)->approveTarget($project, $node, $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]));
    }

    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create(['status' => 'draft']);
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $process = BusinessProcess::factory()->for($project, 'project')->create();

        return [$project, $actor, $process];
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
