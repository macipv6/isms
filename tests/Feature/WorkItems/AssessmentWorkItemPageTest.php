<?php

namespace Tests\Feature\WorkItems;

use App\Enums\ComplianceStatus;
use App\Enums\EvidenceReviewStatus;
use App\Enums\FindingStatus;
use App\Enums\MeasureStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AssessmentQuestion;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\Organization;
use App\Models\ProjectAnswer;
use App\Models\ProjectAssessment;
use App\Models\User;
use App\Services\Assessment\AssessmentStarter;
use App\Services\WorkItems\QuestionWorkItemPresenter;
use Database\Seeders\AssessmentCatalogSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssessmentWorkItemPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_assessment_exposes_safe_question_work_item_context(): void
    {
        [$customer, $project, $assessment, $question, $actor] = $this->context();
        $linked = EvidenceFile::factory()->for($project)->create([
            'original_name' => 'Leitlinie.pdf',
            'status' => EvidenceReviewStatus::Verified,
        ]);
        $candidate = EvidenceFile::factory()->for($project)->create([
            'original_name' => 'Protokoll.txt',
        ]);
        $question->evidenceFiles()->attach($linked->id, $this->questionPivot($project, $assessment));

        $historical = $this->finding($project, $assessment, $question, $actor, FindingStatus::Rejected, '2026-09-01');
        $active = $this->finding($project, $assessment, $question, $actor, FindingStatus::Accepted, '2026-09-02');
        $active->evidenceFiles()->attach($linked->id, [
            'id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'project_assessment_id' => $assessment->id,
        ]);
        Measure::factory()->for($project)->for($active)->create(['status' => MeasureStatus::Completed]);
        Measure::factory()->for($project)->for($active)->create(['status' => MeasureStatus::Planned]);

        $url = '/organizations/'.$customer->id.'/projects/'.$project->id.'/assessment';
        $downloadUrl = '/organizations/'.$customer->id.'/projects/'.$project->id.'/evidence/'.$linked->id.'/download';
        $this->actingAs($actor)->get($url)->assertOk()->assertInertia(
            fn (Assert $page): Assert => $page
                ->where('canAnswer', true)
                ->has('categories.0.questions.1.work_items', fn (Assert $items): Assert => $items
                    ->where('can_upload_evidence', true)
                    ->where('can_propose_finding', false)
                    ->has('linked_evidence', 1)
                    ->where('linked_evidence.0.id', $linked->id)
                    ->where('linked_evidence.0.status', 'verified')
                    ->where('linked_evidence.0.download_url', $downloadUrl)
                    ->missing('linked_evidence.0.storage_path')
                    ->missing('linked_evidence.0.sha256')
                    ->has('available_evidence', 1)
                    ->where('available_evidence.0.id', $candidate->id)
                    ->has('findings', 2)
                    ->where('findings.0.id', $active->id)
                    ->where('findings.0.measures.total', 2)
                    ->where('findings.0.measures.terminal', 1)
                    ->has('findings.0.measures.items', 2)
                    ->missing('findings.0.decision_note')
                    ->missing('findings.0.proposed_by')
                    ->where('findings.1.id', $historical->id)
                    ->etc()
                ),
        );
    }

    public function test_proposal_eligibility_rejects_fulfilled_unanswered_hidden_and_active_questions(): void
    {
        [$customer, $project, $assessment, $partial, $actor] = $this->context();
        $fulfilled = $assessment->questions()->where('question_key', 'governance.policy_exists')->sole();
        $hidden = $assessment->questions()->where('question_key', 'cloud.m365_mfa')->sole();
        $unanswered = $assessment->questions()->where('question_key', 'organization.responsibility')->sole();
        $this->answer($assessment, $fulfilled, $actor, ComplianceStatus::Fulfilled);
        $this->finding($project, $assessment, $partial, $actor, FindingStatus::Proposed, '2026-09-03');

        $presenter = app(QuestionWorkItemPresenter::class);

        self::assertFalse($presenter->for($fulfilled, true)['can_propose_finding']);
        self::assertFalse($presenter->for($unanswered, true)['can_propose_finding']);
        self::assertFalse($presenter->for($hidden, true)['can_propose_finding']);
        self::assertFalse($presenter->for($partial, true)['can_propose_finding']);
    }

    public function test_read_only_assessment_retains_history_without_write_actions(): void
    {
        [$customer, $project, $assessment, $question, $actor] = $this->context([
            'status' => ProjectStatus::Completed,
        ]);
        $finding = $this->finding($project, $assessment, $question, $actor, FindingStatus::Accepted, '2026-09-02');
        Measure::factory()->for($project)->for($finding)->create();
        $evidence = EvidenceFile::factory()->for($project)->create();
        $question->evidenceFiles()->attach($evidence->id, $this->questionPivot($project, $assessment));

        $this->actingAs($actor)
            ->get('/organizations/'.$customer->id.'/projects/'.$project->id.'/assessment')
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('categories.0.questions.1.work_items', fn (Assert $items): Assert => $items
                    ->where('can_upload_evidence', false)
                    ->where('can_propose_finding', false)
                    ->has('linked_evidence', 1)
                    ->where('linked_evidence.0.can_review', false)
                    ->has('findings', 1)
                    ->where('findings.0.can_edit', false)
                    ->where('findings.0.can_decide', false)
                    ->where('findings.0.can_close', false)
                    ->where('findings.0.can_create_measure', false)
                    ->where('findings.0.measures.items.0.can_edit', false)
                    ->where('findings.0.measures.items.0.allowed_transitions', [])
                    ->etc()
                ));
    }

    public function test_assessment_page_eager_loads_question_answers(): void
    {
        [$customer, $project, , , $actor] = $this->context();
        Model::preventLazyLoading();

        try {
            $this->actingAs($actor)
                ->get('/organizations/'.$customer->id.'/projects/'.$project->id.'/assessment')
                ->assertOk();
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    /** @return array{Organization, IsmsProject, ProjectAssessment, AssessmentQuestion, User} */
    private function context(array $projectAttributes = []): array
    {
        $this->seed(AssessmentCatalogSeeder::class);
        $customer = Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);
        $project = IsmsProject::factory()->for($customer)->create($projectAttributes);
        $actor = User::factory()
            ->for(Organization::factory()->create(['organization_type' => 'internal']))
            ->create(['role' => UserRole::Consultant, 'is_active' => true]);
        $assessment = app(AssessmentStarter::class)->start($project, $actor);
        $question = $assessment->questions()->where('question_key', 'governance.objectives')->sole();
        $this->answer($assessment, $question, $actor, ComplianceStatus::Partial);

        return [$customer, $project, $assessment, $question, $actor];
    }

    private function answer(ProjectAssessment $assessment, AssessmentQuestion $question, User $actor, ComplianceStatus $status): void
    {
        ProjectAnswer::query()->create([
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'answer_value' => 'Bewertung',
            'answer_json' => null,
            'comment' => null,
            'compliance_status' => $status,
            'answered_by' => $actor->id,
            'answered_at' => now(),
        ]);
    }

    private function finding(
        IsmsProject $project,
        ProjectAssessment $assessment,
        AssessmentQuestion $question,
        User $actor,
        FindingStatus $status,
        string $date,
    ): Finding {
        return Finding::factory()->for($project)->create([
            'project_assessment_id' => $assessment->id,
            'assessment_question_id' => $question->id,
            'status' => $status,
            'proposed_by' => $actor->id,
            'proposed_at' => $date,
        ]);
    }

    /** @return array{id: string, project_id: string, project_assessment_id: string} */
    private function questionPivot(IsmsProject $project, ProjectAssessment $assessment): array
    {
        return [
            'id' => (string) Str::uuid(),
            'project_id' => $project->id,
            'project_assessment_id' => $assessment->id,
        ];
    }
}
