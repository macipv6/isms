<?php

namespace App\Services\Evidence;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AssessmentQuestion;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\ProjectAssessment;
use App\Models\User;
use App\Services\Assessment\ApplicabilityEvaluator;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EvidenceLinkService
{
    public function __construct(
        private readonly ApplicabilityEvaluator $applicabilityEvaluator,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function linkToQuestion(
        IsmsProject $project,
        EvidenceFile $evidence,
        AssessmentQuestion $question,
        User $actor,
        bool $recordAudit = true,
    ): EvidenceFile {
        return DB::transaction(function () use ($project, $evidence, $question, $actor, $recordAudit): EvidenceFile {
            $lockedProject = $this->lockedWritableProject($project, $actor);
            $lockedEvidence = $this->lockedEvidence($lockedProject, $evidence);
            [$assessment, $lockedQuestion] = $this->applicableQuestion($lockedProject, $question);

            $linked = DB::table('evidence_question_links')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'project_id' => $lockedProject->id,
                'project_assessment_id' => $assessment->id,
                'assessment_question_id' => $lockedQuestion->id,
                'evidence_file_id' => $lockedEvidence->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($recordAudit && $linked === 1) {
                $this->auditLogger->record('evidence.linked', $actor, [
                    'project_id' => $lockedProject->id,
                    'evidence_id' => $lockedEvidence->id,
                    'link_type' => 'question',
                ]);
            }

            return $lockedEvidence;
        });
    }

    public function linkToFinding(
        IsmsProject $project,
        EvidenceFile $evidence,
        Finding $finding,
        User $actor,
    ): EvidenceFile {
        return DB::transaction(function () use ($project, $evidence, $finding, $actor): EvidenceFile {
            $lockedProject = $this->lockedWritableProject($project, $actor);
            $lockedEvidence = $this->lockedEvidence($lockedProject, $evidence);
            $lockedFinding = Finding::query()
                ->whereKey($finding->id)
                ->where('project_id', $lockedProject->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedFinding instanceof Finding) {
                $this->reject('Die Feststellung gehört nicht zu diesem Projekt.');
            }

            $assessment = ProjectAssessment::query()
                ->whereKey($lockedFinding->project_assessment_id)
                ->where('project_id', $lockedProject->id)
                ->lockForUpdate()
                ->first();

            if (! $assessment instanceof ProjectAssessment) {
                $this->reject('Die Feststellung gehört nicht zu dieser Bewertung.');
            }

            $linked = DB::table('evidence_finding_links')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'project_id' => $lockedProject->id,
                'project_assessment_id' => $assessment->id,
                'finding_id' => $lockedFinding->id,
                'evidence_file_id' => $lockedEvidence->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($linked === 1) {
                $this->auditLogger->record('evidence.linked', $actor, [
                    'project_id' => $lockedProject->id,
                    'evidence_id' => $lockedEvidence->id,
                    'finding_id' => $lockedFinding->id,
                    'link_type' => 'finding',
                ]);
            }

            return $lockedEvidence;
        });
    }

    /**
     * @return array{0: ProjectAssessment, 1: AssessmentQuestion}
     */
    public function applicableQuestion(IsmsProject $project, AssessmentQuestion $question): array
    {
        $assessment = ProjectAssessment::query()
            ->whereKey($question->project_assessment_id)
            ->where('project_id', $project->id)
            ->lockForUpdate()
            ->first();

        if (! $assessment instanceof ProjectAssessment) {
            $this->reject('Die Frage gehört nicht zu diesem Projekt.');
        }

        $lockedQuestion = AssessmentQuestion::query()
            ->whereKey($question->id)
            ->where('project_assessment_id', $assessment->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->first();

        if (! $lockedQuestion instanceof AssessmentQuestion
            || ! $this->applicabilityEvaluator->isApplicable(
                $lockedQuestion,
                $this->applicabilityEvaluator->answerValues($assessment),
            )) {
            $this->reject('Diese Frage ist aufgrund der aktuellen Antworten nicht anwendbar.');
        }

        return [$assessment, $lockedQuestion];
    }

    public function assertWritableProject(IsmsProject $project, User $actor): void
    {
        $this->lockedWritableProject($project, $actor);
    }

    private function lockedWritableProject(IsmsProject $project, User $actor): IsmsProject
    {
        $actor->loadMissing('organization');

        if (! $actor->is_active
            || $actor->organization?->organization_type !== 'internal'
            || ! in_array($actor->role, [UserRole::Admin, UserRole::Consultant], true)) {
            $this->reject('Die Aktion ist nicht zulässig.');
        }

        $lockedProject = IsmsProject::query()
            ->with('organization')
            ->whereKey($project->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedProject instanceof IsmsProject
            || $lockedProject->organization?->organization_type !== 'customer'
            || ! $lockedProject->organization->is_active
            || ! in_array($lockedProject->status, [ProjectStatus::Draft, ProjectStatus::Active], true)) {
            $this->reject('Das Projekt ist nicht beschreibbar.');
        }

        return $lockedProject;
    }

    private function lockedEvidence(IsmsProject $project, EvidenceFile $evidence): EvidenceFile
    {
        $lockedEvidence = EvidenceFile::query()
            ->whereKey($evidence->id)
            ->where('project_id', $project->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedEvidence instanceof EvidenceFile) {
            $this->reject('Der Nachweis gehört nicht zu diesem Projekt.');
        }

        return $lockedEvidence;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['evidence' => $message]);
    }
}
