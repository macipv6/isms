<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceReviewStatus;
use App\Enums\ProjectStatus;
use App\Models\AssessmentQuestion;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EvidenceRegisterController extends Controller
{
    public function __invoke(Request $request, Organization $organization, IsmsProject $project): Response
    {
        $this->ensureOwnership($organization, $project);
        Gate::authorize('viewAssessment', $project);

        $validated = validator($request->query(), [
            'status' => ['nullable', Rule::enum(EvidenceReviewStatus::class)],
        ])->validate();
        $status = $validated['status'] ?? null;

        $evidence = EvidenceFile::query()
            ->select(['id', 'project_id', 'original_name', 'mime_type', 'file_kind', 'size_bytes', 'status', 'uploaded_at'])
            ->where('project_id', $project->id)
            ->when($status, fn ($query, string $value) => $query->where('status', $value))
            ->with([
                'questions:id,project_assessment_id,question_key,title',
                'findings:id,project_id,project_assessment_id,assessment_question_id,title,status',
            ])
            ->latest('uploaded_at')
            ->get()
            ->map(fn (EvidenceFile $file): array => [
                'id' => $file->id,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'file_kind' => $file->file_kind,
                'size_bytes' => $file->size_bytes,
                'status' => $file->status->value,
                'uploaded_at' => $file->uploaded_at->toIso8601String(),
                'download_url' => $this->baseUrl($organization, $project).'/evidence/'.$file->id.'/download',
                'questions' => $file->questions->map(fn (AssessmentQuestion $question): array => [
                    'id' => $question->id,
                    'question_key' => $question->question_key,
                    'title' => $question->title,
                ])->values()->all(),
                'findings' => $file->findings->map(fn (Finding $finding): array => [
                    'id' => $finding->id,
                    'title' => $finding->title,
                    'status' => $finding->status->value,
                ])->values()->all(),
            ]);

        return Inertia::render('evidence/Index', [
            'organization' => $organization->only(['id', 'name']),
            'project' => $project->only(['id', 'name']),
            'filters' => ['status' => $status],
            'evidence' => $evidence,
            'canManage' => $this->canManage($organization, $project),
        ]);
    }

    private function ensureOwnership(Organization $organization, IsmsProject $project): void
    {
        abort_unless($organization->organization_type === 'customer' && $project->organization_id === $organization->id, 404);
    }

    private function canManage(Organization $organization, IsmsProject $project): bool
    {
        return $organization->is_active
            && in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Active], true);
    }

    private function baseUrl(Organization $organization, IsmsProject $project): string
    {
        return '/organizations/'.$organization->id.'/projects/'.$project->id;
    }
}
