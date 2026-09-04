<?php

namespace Tests\Feature\Findings;

use App\Enums\FindingStatus;
use App\Enums\MeasureStatus;
use App\Models\AuditEvent;
use App\Models\Finding;
use App\Models\Measure;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Findings\FindingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FindingAuditTest extends TestCase
{
    use InteractsWithFindingWorkflow;
    use RefreshDatabase;

    public function test_real_transitions_are_audited_once_with_redacted_context(): void
    {
        [$customer, $project, $assessment, $question, $actor] = $this->findingContext();
        $workflow = app(FindingWorkflow::class);
        $finding = $workflow->propose($project, $question, $this->findingPayload(), $actor);
        $finding = $workflow->update($finding, $this->findingPayload(['title' => 'Geheimer neuer Titel']), $actor);
        $workflow->update($finding, $this->findingPayload(['title' => 'Geheimer neuer Titel']), $actor);
        $finding = $workflow->decide($finding, FindingStatus::Accepted, 'Vertrauliche Entscheidungsnotiz', $actor);
        Measure::factory()->for($project)->for($finding)->create(['status' => MeasureStatus::Cancelled]);
        $workflow->close($finding, $actor);

        $this->assertDatabaseCount('audit_events', 4);
        foreach (['finding.proposed', 'finding.updated', 'finding.accepted', 'finding.closed'] as $eventType) {
            $this->assertSame(1, AuditEvent::query()->where('event_type', $eventType)->count());
        }
        $updated = AuditEvent::query()->where('event_type', 'finding.updated')->sole();
        $this->assertSame(['title'], $updated->context['changed_fields']);
        $encoded = AuditEvent::query()->get()->toJson();
        $this->assertStringNotContainsString('Geheimer neuer Titel', $encoded);
        $this->assertStringNotContainsString('Vertrauliche Entscheidungsnotiz', $encoded);
        $this->assertStringNotContainsString('Vertraulicher Antwortkommentar', $encoded);
    }

    public function test_audit_failure_rolls_back_every_finding_write(): void
    {
        [$customer, $project, $assessment, $question, $actor] = $this->findingContext();
        $this->app->instance(AuditLogger::class, $this->failingAuditLogger());

        $this->expectAuditFailure(fn () => app(FindingWorkflow::class)->propose($project, $question, $this->findingPayload(), $actor));
        $this->assertDatabaseCount('findings', 0);

        $finding = Finding::factory()->for($project)->create(['proposed_by' => $actor->id]);
        $originalTitle = $finding->title;
        $this->expectAuditFailure(fn () => app(FindingWorkflow::class)->update($finding, $this->findingPayload(['title' => 'Rollback-Titel']), $actor));
        $this->assertSame($originalTitle, $finding->fresh()->title);

        $this->expectAuditFailure(fn () => app(FindingWorkflow::class)->decide($finding->fresh(), FindingStatus::Accepted, null, $actor));
        $this->assertSame(FindingStatus::Proposed, $finding->fresh()->status);

        $finding->update(['status' => FindingStatus::Accepted]);
        Measure::factory()->for($project)->for($finding)->create(['status' => MeasureStatus::Completed]);
        $this->expectAuditFailure(fn () => app(FindingWorkflow::class)->close($finding->fresh(), $actor));
        $this->assertSame(FindingStatus::Accepted, $finding->fresh()->status);
    }

    private function expectAuditFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the audit failure to bubble out.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
    }

    private function failingAuditLogger(): AuditLogger
    {
        return new class extends AuditLogger
        {
            public function record(
                string $eventType,
                ?User $actor,
                array $context = [],
                ?string $organizationId = null,
            ): AuditEvent {
                throw new RuntimeException('audit unavailable');
            }
        };
    }
}
