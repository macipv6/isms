<?php

namespace Tests\Feature\Risks;

use App\Enums\RiskTreatmentAction;
use App\Models\AuditEvent;
use App\Models\EvidenceFile;
use App\Models\Measure;
use App\Models\RiskAnalysisTrigger;
use App\Services\Risks\RiskAssessmentService;
use App\Services\Risks\RiskCycleCompleter;
use App\Services\Risks\RiskTreatmentWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RiskCycleCompletionEvidenceTest extends TestCase
{
    use InteractsWithRiskTreatment;
    use RefreshDatabase;

    public static function evidenceCases(): array
    {
        $cases = [];
        foreach (['accept', 'reduce', 'transfer'] as $action) {
            foreach (['pending_review', 'rejected', 'missing_reviewer', 'missing_review_time', 'verified'] as $state) {
                $cases[$action.' '.$state] = [$action, $state];
            }
        }

        return $cases;
    }

    #[DataProvider('evidenceCases')]
    public function test_completion_requires_every_linked_evidence_to_be_currently_verified(string $action, string $state): void
    {
        [$project, $cycle, $actor, $target] = $this->assessedCycle('rare', 'limited');
        $workflow = app(RiskTreatmentWorkflow::class);
        $workflow->select($cycle, RiskTreatmentAction::from($action), ['rationale' => 'Behandlung', 'counterparty_name' => 'Partner'], $actor);
        if ($action === 'reduce') {
            $measure = Measure::factory()->for($project, 'project')->create(['status' => 'completed', 'completed_by' => $actor->id, 'completed_at' => now()]);
            $workflow->linkMeasure($cycle, $measure, $actor);
        }
        // Transfer has one valid contract as well as the evidence under test.
        $contract = EvidenceFile::factory()->for($project, 'project')->create(['status' => 'verified', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $workflow->linkEvidence($cycle, $contract, $actor);
        $evidence = EvidenceFile::factory()->for($project, 'project')->create(['status' => 'verified', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        $workflow->linkEvidence($cycle, $evidence, $actor);
        app(RiskAssessmentService::class)->assessResidual($cycle, 'rare', 'limited', 'Restbewertung', $actor, $cycle->fresh()->lock_version);
        // Change the persisted record without refreshing the original evidence instance.
        $evidence->newQuery()->whereKey($evidence->id)->update(match ($state) {
            'missing_reviewer' => ['reviewed_by' => null],
            'missing_review_time' => ['reviewed_at' => null],
            default => ['status' => $state],
        });
        $trigger = RiskAnalysisTrigger::query()->create(['project_id' => $project->id, 'business_process_id' => $target->id, 'type' => 'manual', 'source_identity' => 'completion-evidence', 'rationale' => 'Prüfung', 'status' => 'open', 'created_by' => $actor->id]);
        $cycle->triggerLinks()->create(['project_id' => $project->id, 'risk_analysis_trigger_id' => $trigger->id]);
        $before = $cycle->fresh()->getAttributes();
        $audits = AuditEvent::query()->count();

        if ($state === 'verified') {
            app(RiskCycleCompleter::class)->complete($cycle, $actor, $before['lock_version']);
            $this->assertSame('completed', $cycle->fresh()->status->value);
            $this->assertSame('covered', $trigger->fresh()->status->value);
            $this->assertSame($audits + 1, AuditEvent::query()->count());

            return;
        }

        try {
            app(RiskCycleCompleter::class)->complete($cycle, $actor, $before['lock_version']);
            $this->fail('Completion accepted invalid supporting evidence.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('evidence', $exception->errors());
        }
        $this->assertSame($before, $cycle->fresh()->getAttributes());
        $this->assertSame('open', $trigger->fresh()->status->value);
        $this->assertSame($audits, AuditEvent::query()->count());
    }
}
