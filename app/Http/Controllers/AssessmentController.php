<?php

namespace App\Http\Controllers;

use App\Models\AssessmentQuestion;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Assessment\ApplicabilityEvaluator;
use App\Services\Assessment\AssessmentProgress;
use App\Services\Assessment\AssessmentStarter;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentController extends Controller
{
    public function start(
        Request $request,
        Organization $organization,
        IsmsProject $project,
        AssessmentStarter $starter,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->ensureOwnership($organization, $project);
        Gate::authorize('startAssessment', $project);

        $assessment = $starter->start($project, $this->actor($request));

        if ($assessment->wasRecentlyCreated) {
            $audit->record(
                'assessment.started',
                $this->actor($request),
                [
                    'project_id' => $project->id,
                    'catalog_version' => $assessment->catalogVersion->version,
                ],
                $organization->id,
            );
        }

        return redirect($this->url($organization, $project));
    }

    public function show(
        Organization $organization,
        IsmsProject $project,
        ApplicabilityEvaluator $evaluator,
        AssessmentProgress $progress,
    ): Response {
        $this->ensureOwnership($organization, $project);
        Gate::authorize('viewAssessment', $project);

        $assessment = $project->assessment()
            ->with('catalogVersion')
            ->firstOrFail();
        $questions = $evaluator->applicableQuestions($assessment);
        $categories = [];

        foreach ($questions->groupBy('category_key') as $categoryQuestions) {
            /** @var AssessmentQuestion $first */
            $first = $categoryQuestions->first();
            $categories[] = [
                'key' => $first->category_key,
                'name' => $first->category_name,
                'questions' => $categoryQuestions
                    ->map(fn (AssessmentQuestion $question): array => $this->questionData($question))
                    ->values()
                    ->all(),
            ];
        }

        return Inertia::render('assessments/Show', [
            'organization' => $organization->only(['id', 'name']),
            'project' => $project->only(['id', 'name']),
            'catalogVersion' => $assessment->catalogVersion->version,
            'progress' => $progress->for($assessment),
            'categories' => $categories,
            'canAnswer' => Gate::allows('answerAssessment', $project),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function questionData(AssessmentQuestion $question): array
    {
        $answer = $question->answer;

        return [
            'id' => $question->id,
            'question_key' => $question->question_key,
            'title' => $question->title,
            'question_text' => $question->question_text,
            'help_text' => $question->help_text,
            'answer_type' => $question->answer_type->value,
            'severity' => $question->severity,
            'evidence_expected' => $question->evidence_expected,
            'options' => $question->options,
            'answer' => $answer?->valueForRules(),
            'compliance_status' => $answer?->compliance_status->value,
            'comment' => $answer?->comment,
        ];
    }

    private function ensureOwnership(Organization $organization, IsmsProject $project): void
    {
        abort_unless($organization->organization_type === 'customer', 404);
        abort_unless($project->organization_id === $organization->id, 404);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function url(Organization $organization, IsmsProject $project): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id.'/assessment';
    }
}
