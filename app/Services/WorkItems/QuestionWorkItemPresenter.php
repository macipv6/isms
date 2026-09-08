<?php

namespace App\Services\WorkItems;

use App\Enums\AssessmentStatus;
use App\Enums\ComplianceStatus;
use App\Enums\FindingStatus;
use App\Enums\MeasureStatus;
use App\Models\AssessmentQuestion;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\ProjectAnswer;
use App\Models\ProjectAssessment;
use App\Services\Assessment\ApplicabilityEvaluator;

class QuestionWorkItemPresenter
{
    /** @var array<string, array<string, mixed>> */
    private array $answerValues = [];

    public function __construct(private readonly ApplicabilityEvaluator $applicability) {}

    /** @return array<string, mixed> */
    public function for(AssessmentQuestion $question, bool $canManage): array
    {
        $question->loadMissing([
            'answer',
            'assessment.project.organization',
            'assessment.project.evidenceFiles',
            'evidenceFiles',
            'findings.evidenceFiles',
            'findings.measures',
        ]);

        $assessment = $question->assessment;
        $project = $assessment->project;
        $applicable = $question->is_active && $this->isApplicable($question, $assessment);
        $activeFindingExists = $question->findings->contains(
            fn (Finding $finding): bool => in_array($finding->status, [FindingStatus::Proposed, FindingStatus::Accepted], true),
        );
        $linkedIds = $question->evidenceFiles->pluck('id')->all();
        $availableEvidence = $project->evidenceFiles
            ->reject(fn (EvidenceFile $evidence): bool => in_array($evidence->id, $linkedIds, true))
            ->sortByDesc('uploaded_at')
            ->values();

        return [
            'can_upload_evidence' => $canManage && $applicable,
            'can_propose_finding' => $canManage
                && $applicable
                && $assessment->status === AssessmentStatus::InProgress
                && $question->answer instanceof ProjectAnswer
                && in_array($question->answer->compliance_status, [ComplianceStatus::Partial, ComplianceStatus::NotFulfilled], true)
                && ! $activeFindingExists,
            'upload_url' => $this->baseUrl($project).'/assessment/questions/'.$question->id.'/evidence',
            'linked_evidence' => $question->evidenceFiles
                ->sortByDesc('uploaded_at')
                ->values()
                ->map(fn (EvidenceFile $evidence): array => $this->evidence($evidence, $project, $canManage))
                ->all(),
            'available_evidence' => $availableEvidence
                ->map(fn (EvidenceFile $evidence): array => [
                    ...$this->evidenceSummary($evidence),
                    'link_url' => $this->baseUrl($project).'/evidence/'.$evidence->id.'/questions/'.$question->id,
                ])
                ->all(),
            'findings' => $question->findings
                ->sortByDesc('proposed_at')
                ->values()
                ->map(fn (Finding $finding): array => $this->finding($finding, $project, $canManage))
                ->all(),
            'propose_finding_url' => $this->baseUrl($project).'/assessment/questions/'.$question->id.'/findings',
        ];
    }

    private function isApplicable(AssessmentQuestion $question, ProjectAssessment $assessment): bool
    {
        if (! array_key_exists($assessment->id, $this->answerValues)) {
            $this->answerValues[$assessment->id] = $assessment->relationLoaded('answers')
                ? $assessment->answers
                    ->mapWithKeys(fn (ProjectAnswer $answer): array => [
                        $answer->question->question_key => $answer->valueForRules(),
                    ])
                    ->all()
                : $this->applicability->answerValues($assessment);
        }

        return $this->applicability->isApplicable($question, $this->answerValues[$assessment->id]);
    }

    /** @return array<string, mixed> */
    private function evidence(EvidenceFile $evidence, IsmsProject $project, bool $canManage): array
    {
        return [
            ...$this->evidenceSummary($evidence),
            'uploaded_at' => $evidence->uploaded_at->toIso8601String(),
            'download_url' => $this->baseUrl($project).'/evidence/'.$evidence->id.'/download',
            'review_url' => $this->baseUrl($project).'/evidence/'.$evidence->id.'/review',
            'can_review' => $canManage,
        ];
    }

    /** @return array{id: string, original_name: string, mime_type: string, file_kind: string, size_bytes: int, status: string} */
    private function evidenceSummary(EvidenceFile $evidence): array
    {
        return [
            'id' => $evidence->id,
            'original_name' => $evidence->original_name,
            'mime_type' => $evidence->mime_type,
            'file_kind' => $evidence->file_kind,
            'size_bytes' => $evidence->size_bytes,
            'status' => $evidence->status->value,
        ];
    }

    /** @return array<string, mixed> */
    private function finding(Finding $finding, IsmsProject $project, bool $canManage): array
    {
        $base = $this->baseUrl($project);
        $isProposed = $finding->status === FindingStatus::Proposed;
        $isAccepted = $finding->status === FindingStatus::Accepted;
        $terminal = $finding->measures->filter(
            fn (Measure $measure): bool => in_array($measure->status, [MeasureStatus::Completed, MeasureStatus::Cancelled], true),
        )->count();
        $linkedIds = $finding->evidenceFiles->pluck('id')->all();

        return [
            'id' => $finding->id,
            'title' => $finding->title,
            'description' => $finding->description,
            'severity' => $finding->severity->value,
            'status' => $finding->status->value,
            'proposed_at' => $finding->proposed_at->toIso8601String(),
            'can_edit' => $canManage && $isProposed,
            'can_decide' => $canManage && $isProposed,
            'can_close' => $canManage && $isAccepted && $finding->measures->isNotEmpty() && $terminal === $finding->measures->count(),
            'can_create_measure' => $canManage && $isAccepted,
            'can_link_evidence' => $canManage,
            'update_url' => $base.'/findings/'.$finding->id,
            'decision_url' => $base.'/findings/'.$finding->id.'/decision',
            'close_url' => $base.'/findings/'.$finding->id.'/close',
            'create_measure_url' => $base.'/findings/'.$finding->id.'/measures',
            'evidence' => $finding->evidenceFiles
                ->sortByDesc('uploaded_at')
                ->values()
                ->map(fn (EvidenceFile $evidence): array => $this->evidence($evidence, $project, $canManage))
                ->all(),
            'available_evidence' => $project->evidenceFiles
                ->reject(fn (EvidenceFile $evidence): bool => in_array($evidence->id, $linkedIds, true))
                ->sortByDesc('uploaded_at')
                ->values()
                ->map(fn (EvidenceFile $evidence): array => [
                    ...$this->evidenceSummary($evidence),
                    'link_url' => $base.'/findings/'.$finding->id.'/evidence/'.$evidence->id,
                ])
                ->all(),
            'measures' => [
                'total' => $finding->measures->count(),
                'terminal' => $terminal,
                'items' => $finding->measures
                    ->sortBy('due_date')
                    ->values()
                    ->map(fn (Measure $measure): array => $this->measure($measure, $base, $canManage && $isAccepted))
                    ->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function measure(Measure $measure, string $base, bool $canManage): array
    {
        $transitions = match ($measure->status) {
            MeasureStatus::Planned => [MeasureStatus::InProgress->value, MeasureStatus::Cancelled->value],
            MeasureStatus::InProgress => [MeasureStatus::Blocked->value, MeasureStatus::Completed->value, MeasureStatus::Cancelled->value],
            MeasureStatus::Blocked => [MeasureStatus::InProgress->value, MeasureStatus::Cancelled->value],
            MeasureStatus::Completed, MeasureStatus::Cancelled => [],
        };

        return [
            'id' => $measure->id,
            'title' => $measure->title,
            'description' => $measure->description,
            'priority' => $measure->priority->value,
            'responsible_name' => $measure->responsible_name,
            'responsible_email' => $measure->responsible_email,
            'due_date' => $measure->due_date->toDateString(),
            'status' => $measure->status->value,
            'can_edit' => $canManage && ! in_array($measure->status, [MeasureStatus::Completed, MeasureStatus::Cancelled], true),
            'allowed_transitions' => $canManage ? $transitions : [],
            'update_url' => $base.'/measures/'.$measure->id,
            'transition_url' => $base.'/measures/'.$measure->id.'/status',
        ];
    }

    private function baseUrl(IsmsProject $project): string
    {
        return '/organizations/'.$project->organization_id.'/projects/'.$project->id;
    }
}
