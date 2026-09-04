<?php

namespace Tests\Feature\Findings;

use App\Enums\ComplianceStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\MeasureStatus;
use App\Models\Measure;
use App\Models\ProjectAnswer;
use App\Services\Findings\FindingWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FindingWorkflowTest extends TestCase
{
    use InteractsWithFindingWorkflow;
    use RefreshDatabase;

    public function test_gap_can_be_proposed_edited_accepted_and_closed_after_all_measures_are_terminal(): void
    {
        [$customer, $project, $assessment, $question, $actor] = $this->findingContext();
        $workflow = app(FindingWorkflow::class);

        $finding = $workflow->propose($project, $question, $this->findingPayload(), $actor);
        $this->assertSame(FindingStatus::Proposed, $finding->status);
        $this->assertSame($actor->id, $finding->proposed_by);
        $this->assertNotNull($finding->proposed_at);

        $finding = $workflow->update($finding, $this->findingPayload([
            'title' => 'Aktualisierte Feststellung',
            'severity' => FindingSeverity::Critical->value,
        ]), $actor);
        $this->assertSame('Aktualisierte Feststellung', $finding->title);
        $this->assertSame(FindingSeverity::Critical, $finding->severity);

        $finding = $workflow->decide($finding, FindingStatus::Accepted, null, $actor);
        $this->assertSame(FindingStatus::Accepted, $finding->status);
        $this->assertSame($actor->id, $finding->decided_by);
        $this->assertNotNull($finding->decided_at);

        Measure::factory()->for($project)->for($finding)->create(['status' => MeasureStatus::Completed]);
        $finding = $workflow->close($finding, $actor);
        $this->assertSame(FindingStatus::Closed, $finding->status);
        $this->assertSame($actor->id, $finding->closed_by);
        $this->assertNotNull($finding->closed_at);
    }

    public function test_only_applicable_active_answered_gaps_can_be_proposed(): void
    {
        foreach ([ComplianceStatus::Fulfilled, ComplianceStatus::NotApplicable] as $status) {
            [$customer, $project, $assessment, $question, $actor] = $this->findingContext($status);
            $this->assertProposalRejected($project, $question, $actor);
        }

        [$customer, $project, $assessment, $question, $actor] = $this->findingContext();
        ProjectAnswer::query()->where('assessment_question_id', $question->id)->delete();
        $this->assertProposalRejected($project, $question, $actor);

        [$customer, $project, $assessment, $question, $actor] = $this->findingContext();
        $question->update(['is_active' => false]);
        $this->assertProposalRejected($project, $question, $actor);

        [$customer, $project, $assessment, $question, $actor] = $this->findingContext();
        $assessment->update(['status' => \App\Enums\AssessmentStatus::Completed]);
        $this->assertProposalRejected($project, $question, $actor);

        [$customer, $project, $assessment, $question, $actor] = $this->findingContext();
        $question->update(['rules' => [[
            'trigger_question_key' => 'missing.trigger',
            'operator' => 'equals',
            'expected_value' => true,
            'action' => 'include',
        ]]]);
        $this->assertProposalRejected($project, $question, $actor);

        [$customer, $project, $assessment, $question, $actor] = $this->findingContext(ComplianceStatus::NotFulfilled);
        $foreignProject = $this->findingContext()[1];
        $this->assertProposalRejected($foreignProject, $question, $actor);

        $this->assertDatabaseCount('findings', 0);
    }

    public function test_decision_content_and_closure_guards_preserve_history(): void
    {
        [$customer, $project, $assessment, $question, $actor] = $this->findingContext();
        $workflow = app(FindingWorkflow::class);
        $finding = $workflow->propose($project, $question, $this->findingPayload(), $actor);

        $this->expectValidationError(
            fn () => $workflow->decide($finding, FindingStatus::Rejected, null, $actor),
            'decision_note',
        );
        $rejected = $workflow->decide($finding, FindingStatus::Rejected, 'Nicht hinreichend belegt', $actor);
        $this->expectValidationError(
            fn () => $workflow->update($rejected, $this->findingPayload(['title' => 'Verboten']), $actor),
            'finding',
        );

        $replacement = $workflow->propose($project, $question, $this->findingPayload(['title' => 'Neuer Vorschlag']), $actor);
        $this->expectValidationError(
            fn () => $workflow->propose($project, $question, $this->findingPayload(), $actor),
            'finding',
        );
        $accepted = $workflow->decide($replacement, FindingStatus::Accepted, null, $actor);
        $this->expectValidationError(fn () => $workflow->close($accepted, $actor), 'finding');

        Measure::factory()->for($project)->for($accepted)->create(['status' => MeasureStatus::InProgress]);
        $this->expectValidationError(fn () => $workflow->close($accepted, $actor), 'finding');
    }

    private function assertProposalRejected(object $project, object $question, object $actor): void
    {
        $this->expectValidationError(
            fn () => app(FindingWorkflow::class)->propose($project, $question, $this->findingPayload(), $actor),
            'finding',
        );
    }

    private function expectValidationError(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
