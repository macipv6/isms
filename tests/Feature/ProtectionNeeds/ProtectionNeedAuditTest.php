<?php

namespace Tests\Feature\ProtectionNeeds;

use App\Data\Dependencies\DependencyNode;
use App\Models\AuditEvent;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProtectionNeed;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\ProtectionNeeds\ProtectionNeedPropagator;
use App\Services\ProtectionNeeds\ProtectionNeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ProtectionNeedAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalculation_and_invalidation_events_are_redacted_and_idempotent(): void
    {
        [$project, $actor, $process] = $this->approvedContext();
        $priorRecalculations = AuditEvent::query()->where('event_type', 'protection_need.recalculated')->count();
        $this->changeConfidentiality($project, $actor, $process, 'high', 'Geheimer Bewertungstext');

        $invalidations = AuditEvent::query()->where('event_type', 'protection_need.invalidated')->get();
        $recalculations = AuditEvent::query()->where('event_type', 'protection_need.recalculated')->get();
        $this->assertCount(3, $invalidations);
        $this->assertCount($priorRecalculations + 1, $recalculations);
        $this->assertSame($actor->id, $recalculations->last()->actor_user_id);
        $this->assertSame('high', $recalculations->last()->context['new_level']);
        $this->assertStringNotContainsString('Geheimer Bewertungstext', AuditEvent::query()->get()->toJson());

        app(ProtectionNeedPropagator::class)->recalculate($project, [DependencyNode::process($process)]);
        $this->assertSame(3, AuditEvent::query()->where('event_type', 'protection_need.invalidated')->count());
        $this->assertSame($priorRecalculations + 1, AuditEvent::query()->where('event_type', 'protection_need.recalculated')->count());
    }

    public function test_recalculation_audit_failure_rolls_back_rating_and_invalidation(): void
    {
        [$project, $actor, $process] = $this->approvedContext();
        $before = AuditEvent::query()->count();
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                if ($eventType === 'protection_need.recalculated') {
                    throw new RuntimeException('audit unavailable');
                }

                return parent::record($eventType, $actor, $context, $organizationId);
            }
        });
        try {
            $this->changeConfidentiality($project, $actor, $process, 'high', 'Geheimer Bewertungstext');
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $need = ProtectionNeed::query()->where('business_process_id', $process->id)->where('objective', 'confidentiality')->firstOrFail();
        $this->assertSame('normal', $need->direct_level->value);
        $this->assertSame('normal', $need->effective_level->value);
        $this->assertSame('approved', $need->status->value);
        $this->assertSame($before, AuditEvent::query()->count());
    }

    private function approvedContext(): array
    {
        $project = IsmsProject::factory()->for(Organization::factory()->state(['organization_type' => 'customer', 'entra_tenant_id' => null]))->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $process = BusinessProcess::factory()->for($project, 'project')->create();
        $node = DependencyNode::process($process);
        $service = app(ProtectionNeedService::class);
        $service->rateTarget($project, $node, [
            'confidentiality' => ['level' => 'normal', 'rationale' => 'Erste Bewertung'],
            'integrity' => ['level' => 'normal', 'rationale' => 'Erste Bewertung'],
            'availability' => ['level' => 'normal', 'rationale' => 'Erste Bewertung'],
        ], $actor, ['confidentiality' => 0, 'integrity' => 0, 'availability' => 0]);
        $service->approveTarget($project, $node, $actor, $this->versions($process));

        return [$project, $actor, $process];
    }

    private function changeConfidentiality(IsmsProject $project, User $actor, BusinessProcess $process, string $level, string $rationale): void
    {
        app(ProtectionNeedService::class)->rateTarget($project, DependencyNode::process($process), ['confidentiality' => ['level' => $level, 'rationale' => $rationale]], $actor, $this->versions($process));
    }

    private function versions(BusinessProcess $process): array
    {
        return ProtectionNeed::query()->where('business_process_id', $process->id)->get()->mapWithKeys(fn (ProtectionNeed $need): array => [$need->objective->value => $need->lock_version])->all();
    }
}
