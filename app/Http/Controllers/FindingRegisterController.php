<?php

namespace App\Http\Controllers;

use App\Enums\FindingStatus;
use App\Enums\MeasureStatus;
use App\Enums\ProjectStatus;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FindingRegisterController extends Controller
{
    public function __invoke(Request $request, Organization $organization, IsmsProject $project): Response
    {
        $this->ensureOwnership($organization, $project);
        Gate::authorize('viewAssessment', $project);

        $validated = validator($request->query(), [
            'status' => ['nullable', Rule::enum(FindingStatus::class)],
        ])->validate();
        $status = $validated['status'] ?? null;
        $terminalStatuses = [MeasureStatus::Completed->value, MeasureStatus::Cancelled->value];

        $findings = Finding::query()
            ->select(['id', 'project_id', 'assessment_question_id', 'title', 'description', 'severity', 'status', 'proposed_at'])
            ->where('project_id', $project->id)
            ->when($status, fn ($query, string $value) => $query->where('status', $value))
            ->with('question:id,question_key,title')
            ->withCount([
                'measures',
                'measures as terminal_measures_count' => fn ($query) => $query->whereIn('status', $terminalStatuses),
            ])
            ->orderByRaw("case severity when 'critical' then 1 when 'high' then 2 when 'medium' then 3 else 4 end")
            ->latest('proposed_at')
            ->get()
            ->map(fn (Finding $finding): array => [
                'id' => $finding->id,
                'title' => $finding->title,
                'description' => $finding->description,
                'severity' => $finding->severity->value,
                'status' => $finding->status->value,
                'proposed_at' => $finding->proposed_at->toIso8601String(),
                'question_id' => $finding->question->id,
                'question_key' => $finding->question->question_key,
                'question_title' => $finding->question->title,
                'measures' => [
                    'total' => (int) $finding->getAttribute('measures_count'),
                    'terminal' => (int) $finding->getAttribute('terminal_measures_count'),
                ],
            ]);

        return Inertia::render('findings/Index', [
            'organization' => $organization->only(['id', 'name']),
            'project' => $project->only(['id', 'name']),
            'filters' => ['status' => $status],
            'findings' => $findings,
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
}
