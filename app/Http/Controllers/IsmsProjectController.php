<?php

namespace App\Http\Controllers;

use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IsmsProjectController extends Controller
{
    public function create(Organization $organization): Response
    {
        $this->ensureCustomer($organization);
        Gate::authorize('create', [IsmsProject::class, $organization]);

        return Inertia::render('projects/Create', [
            'organization' => $organization->only(['id', 'name']),
            'defaults' => [
                'framework' => 'BSI',
                'approach' => 'basis_absicherung',
                'bcm_level' => 'aufbau_bcms',
                'status' => 'draft',
            ],
        ]);
    }

    public function edit(
        Organization $organization,
        IsmsProject $project,
    ): Response {
        $this->ensureCustomer($organization);
        $this->ensureProjectBelongsToOrganization($project, $organization);
        Gate::authorize('update', $project);

        return Inertia::render('projects/Edit', [
            'organization' => $organization->only(['id', 'name']),
            'project' => [
                ...$project->only([
                    'id',
                    'name',
                    'description',
                    'framework',
                    'approach',
                    'bcm_level',
                    'status',
                    'scope_text',
                ]),
                'started_at' => $project->started_at?->toDateString(),
                'target_date' => $project->target_date?->toDateString(),
                'completed_at' => $project->completed_at?->toDateString(),
            ],
        ]);
    }

    public function store(
        StoreProjectRequest $request,
        Organization $organization,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->ensureCustomer($organization);
        Gate::authorize('create', [IsmsProject::class, $organization]);

        $data = $request->validated();
        $project = new IsmsProject($data);
        $project->created_by = $this->actor($request)->id;
        $organization->projects()->save($project);

        $audit->record(
            'project.created',
            $this->actor($request),
            [
                'project_id' => $project->id,
                'changed_fields' => array_keys($data),
            ],
            $organization->id,
        );

        return redirect('/organizations/'.$organization->id);
    }

    public function update(
        UpdateProjectRequest $request,
        Organization $organization,
        IsmsProject $project,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->ensureCustomer($organization);
        $this->ensureProjectBelongsToOrganization($project, $organization);
        Gate::authorize('update', $project);

        $project->fill($request->validated());
        $changedFields = array_keys($project->getDirty());
        $project->save();

        if ($changedFields !== []) {
            $audit->record(
                'project.updated',
                $this->actor($request),
                [
                    'project_id' => $project->id,
                    'changed_fields' => $changedFields,
                ],
                $organization->id,
            );
        }

        if (in_array('status', $changedFields, true)) {
            $audit->record(
                'project.status_changed',
                $this->actor($request),
                [
                    'project_id' => $project->id,
                    'changed_fields' => ['status'],
                ],
                $organization->id,
            );
        }

        return redirect('/organizations/'.$organization->id);
    }

    private function ensureCustomer(Organization $organization): void
    {
        abort_unless($organization->organization_type === 'customer', 404);
    }

    private function ensureProjectBelongsToOrganization(
        IsmsProject $project,
        Organization $organization,
    ): void {
        abort_unless($project->organization_id === $organization->id, 404);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
