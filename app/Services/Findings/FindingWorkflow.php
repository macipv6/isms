<?php

namespace App\Services\Findings;

use App\Enums\AssessmentStatus;
use App\Enums\ComplianceStatus;
use App\Enums\FindingSeverity;
use App\Enums\FindingStatus;
use App\Enums\MeasureStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AssessmentQuestion;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\ProjectAnswer;
use App\Models\ProjectAssessment;
use App\Models\User;
use App\Services\Assessment\ApplicabilityEvaluator;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FindingWorkflow
{
    public function __construct(
        private readonly ApplicabilityEvaluator $applicability,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function propose(IsmsProject $project, AssessmentQuestion $question, array $data, User $actor): Finding
    {
        $validated = $this->validateContent($data);

        return DB::transaction(function () use ($project, $question, $validated, $actor): Finding {
            $lockedProject = $this->lockedWritableProject($project, $actor);
            [$assessment, $lockedQuestion] = $this->eligibleQuestion($lockedProject, $question);

            $activeFinding = Finding::query()
                ->where('assessment_question_id', $lockedQuestion->id)
                ->whereIn('status', [FindingStatus::Proposed->value, FindingStatus::Accepted->value])
                ->lockForUpdate()
                ->exists();
            if ($activeFinding) {
                $this->reject('Für diese Frage besteht bereits eine offene Feststellung.');
            }

            $finding = Finding::query()->create([
                ...$validated,
                'project_id' => $lockedProject->id,
                'project_assessment_id' => $assessment->id,
                'assessment_question_id' => $lockedQuestion->id,
                'status' => FindingStatus::Proposed,
                'proposed_by' => $actor->id,
                'proposed_at' => now(),
            ]);
            $this->audit->record('finding.proposed', $actor, [
                'project_id' => $lockedProject->id,
                'finding_id' => $finding->id,
                'new_status' => FindingStatus::Proposed->value,
            ], $lockedProject->organization_id);

            return $finding;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Finding $finding, array $data, User $actor): Finding
    {
        $validated = $this->validateContent($data);

        return DB::transaction(function () use ($finding, $validated, $actor): Finding {
            $project = $this->lockedWritableProject($finding->project, $actor);
            $locked = $this->lockedFinding($project, $finding);
            if ($locked->status !== FindingStatus::Proposed) {
                $this->reject('Nur vorgeschlagene Feststellungen können bearbeitet werden.');
            }

            $locked->fill($validated);
            $changedFields = array_values(array_intersect(
                ['title', 'description', 'severity'],
                array_keys($locked->getDirty()),
            ));
            if ($changedFields === []) {
                return $locked;
            }

            $locked->save();
            $this->audit->record('finding.updated', $actor, [
                'project_id' => $project->id,
                'finding_id' => $locked->id,
                'changed_fields' => $changedFields,
            ], $project->organization_id);

            return $locked;
        });
    }

    public function decide(Finding $finding, FindingStatus $decision, ?string $note, User $actor): Finding
    {
        if (! in_array($decision, [FindingStatus::Accepted, FindingStatus::Rejected], true)) {
            throw ValidationException::withMessages(['status' => 'Diese Entscheidung ist nicht zulässig.']);
        }
        $validated = Validator::make(
            ['decision_note' => $note],
            ['decision_note' => [$decision === FindingStatus::Rejected ? 'required' : 'nullable', 'string', 'max:10000']],
        )->validate();
        $validatedNote = $validated['decision_note'] ?? null;
        assert($validatedNote === null || is_string($validatedNote));

        return DB::transaction(function () use ($finding, $decision, $validatedNote, $actor): Finding {
            $project = $this->lockedWritableProject($finding->project, $actor);
            $locked = $this->lockedFinding($project, $finding);
            if ($locked->status !== FindingStatus::Proposed) {
                $this->reject('Nur vorgeschlagene Feststellungen können entschieden werden.');
            }

            $oldStatus = $locked->status;
            $locked->update([
                'status' => $decision,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'decision_note' => $validatedNote,
            ]);
            $this->audit->record('finding.'.$decision->value, $actor, [
                'project_id' => $project->id,
                'finding_id' => $locked->id,
                'old_status' => $oldStatus->value,
                'new_status' => $decision->value,
            ], $project->organization_id);

            return $locked;
        });
    }

    public function close(Finding $finding, User $actor): Finding
    {
        return DB::transaction(function () use ($finding, $actor): Finding {
            $project = $this->lockedWritableProject($finding->project, $actor);
            $locked = $this->lockedFinding($project, $finding);
            if ($locked->status !== FindingStatus::Accepted) {
                $this->reject('Nur akzeptierte Feststellungen können geschlossen werden.');
            }

            $measures = $locked->measures()->lockForUpdate()->get();
            if ($measures->isEmpty() || $measures->contains(
                fn (Measure $measure): bool => ! in_array($measure->status, [MeasureStatus::Completed, MeasureStatus::Cancelled], true),
            )) {
                $this->reject('Alle Maßnahmen müssen abgeschlossen oder abgebrochen sein.');
            }

            $oldStatus = $locked->status;
            $locked->update([
                'status' => FindingStatus::Closed,
                'closed_by' => $actor->id,
                'closed_at' => now(),
            ]);
            $this->audit->record('finding.closed', $actor, [
                'project_id' => $project->id,
                'finding_id' => $locked->id,
                'old_status' => $oldStatus->value,
                'new_status' => FindingStatus::Closed->value,
            ], $project->organization_id);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title: string, description: string, severity: string}
     */
    private function validateContent(array $data): array
    {
        $validated = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:10000'],
            'severity' => ['required', Rule::enum(FindingSeverity::class)],
        ])->validate();

        /** @var array{title: string, description: string, severity: string} $validated */
        return $validated;
    }

    /** @return array{ProjectAssessment, AssessmentQuestion} */
    private function eligibleQuestion(IsmsProject $project, AssessmentQuestion $question): array
    {
        $assessment = ProjectAssessment::query()
            ->whereKey($question->project_assessment_id)
            ->where('project_id', $project->id)
            ->where('status', AssessmentStatus::InProgress->value)
            ->lockForUpdate()
            ->first();
        if (! $assessment instanceof ProjectAssessment) {
            $this->reject('Die Frage gehört nicht zu einer aktiven Bewertung dieses Projekts.');
        }

        $lockedQuestion = AssessmentQuestion::query()
            ->whereKey($question->id)
            ->where('project_assessment_id', $assessment->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();
        if (! $lockedQuestion instanceof AssessmentQuestion) {
            $this->reject('Die Frage ist nicht aktiv oder gehört nicht zu dieser Bewertung.');
        }

        ProjectAnswer::query()
            ->where('project_assessment_id', $assessment->id)
            ->lockForUpdate()
            ->get();
        $answer = ProjectAnswer::query()
            ->where('project_assessment_id', $assessment->id)
            ->where('assessment_question_id', $lockedQuestion->id)
            ->first();
        if (! $answer instanceof ProjectAnswer
            || ! in_array($answer->compliance_status, [ComplianceStatus::Partial, ComplianceStatus::NotFulfilled], true)
            || ! $this->applicability->isApplicable($lockedQuestion, $this->applicability->answerValues($assessment))) {
            $this->reject('Nur anwendbare, teilweise oder nicht erfüllte Fragen können eine Feststellung erhalten.');
        }

        return [$assessment, $lockedQuestion];
    }

    private function lockedWritableProject(IsmsProject $project, User $actor): IsmsProject
    {
        $actor->loadMissing('organization');
        if (! $actor->is_active
            || $actor->organization?->organization_type !== 'internal'
            || ! in_array($actor->role, [UserRole::Admin, UserRole::Consultant], true)) {
            $this->reject('Die Aktion ist nicht zulässig.');
        }

        $locked = IsmsProject::query()->with('organization')->whereKey($project->id)->lockForUpdate()->first();
        if (! $locked instanceof IsmsProject
            || $locked->organization?->organization_type !== 'customer'
            || ! $locked->organization->is_active
            || ! in_array($locked->status, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            $this->reject('Das Projekt ist nicht beschreibbar.');
        }

        return $locked;
    }

    private function lockedFinding(IsmsProject $project, Finding $finding): Finding
    {
        $locked = Finding::query()
            ->whereKey($finding->id)
            ->where('project_id', $project->id)
            ->lockForUpdate()
            ->first();
        if (! $locked instanceof Finding) {
            $this->reject('Die Feststellung gehört nicht zu diesem Projekt.');
        }

        return $locked;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['finding' => $message]);
    }
}
