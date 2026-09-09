<?php

namespace App\Http\Controllers;

use App\Enums\MeasurePriority;
use App\Enums\MeasureStatus;
use App\Enums\ProjectStatus;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MeasureRegisterController extends Controller
{
    public function __invoke(Request $request, Organization $organization, IsmsProject $project): Response
    {
        $this->ensureOwnership($organization, $project);
        Gate::authorize('viewAssessment', $project);

        $dueToRules = ['nullable', 'date_format:Y-m-d'];

        if ($request->filled('due_from')) {
            $dueToRules[] = 'after_or_equal:due_from';
        }

        $validated = validator($request->query(), [
            'status' => ['nullable', Rule::enum(MeasureStatus::class)],
            'priority' => ['nullable', Rule::enum(MeasurePriority::class)],
            'due_from' => ['nullable', 'date_format:Y-m-d'],
            'due_to' => $dueToRules,
        ])->validate();
        $status = $validated['status'] ?? null;
        $priority = $validated['priority'] ?? null;
        $dueFrom = $validated['due_from'] ?? null;
        $dueTo = $validated['due_to'] ?? null;

        $measures = Measure::query()
            ->select([
                'id', 'project_id', 'finding_id', 'title', 'description', 'priority', 'responsible_name',
                'responsible_email', 'due_date', 'status',
            ])
            ->where('project_id', $project->id)
            ->when($status, fn ($query, string $value) => $query->where('status', $value))
            ->when($priority, fn ($query, string $value) => $query->where('priority', $value))
            ->when($dueFrom, fn ($query, string $value) => $query->whereDate('due_date', '>=', $value))
            ->when($dueTo, fn ($query, string $value) => $query->whereDate('due_date', '<=', $value))
            ->with('finding:id,project_id,assessment_question_id,title,status')
            ->with('finding.question:id,question_key,title')
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->orderByRaw("case priority when 'critical' then 1 when 'high' then 2 when 'medium' then 3 else 4 end")
            ->get()
            ->map(fn (Measure $measure): array => [
                'id' => $measure->id,
                'title' => $measure->title,
                'description' => $measure->description,
                'priority' => $measure->priority->value,
                'responsible_name' => $measure->responsible_name,
                'responsible_email' => $measure->responsible_email,
                'due_date' => $measure->due_date->toDateString(),
                'status' => $measure->status->value,
                'finding' => [
                    'id' => $measure->finding->id,
                    'title' => $measure->finding->title,
                    'status' => $measure->finding->status->value,
                    'question_id' => $measure->finding->question->id,
                    'question_key' => $measure->finding->question->question_key,
                    'question_title' => $measure->finding->question->title,
                ],
            ]);

        return Inertia::render('measures/Index', [
            'organization' => $organization->only(['id', 'name']),
            'project' => $project->only(['id', 'name']),
            'filters' => [
                'status' => $status,
                'priority' => $priority,
                'due_from' => $dueFrom,
                'due_to' => $dueTo,
            ],
            'measures' => $measures,
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
