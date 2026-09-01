<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\ProjectAssessment;
use App\Models\User;
use App\Services\Assessment\AssessmentProgress;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Organization::class);

        $organizations = Organization::query()
            ->where('organization_type', 'customer')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'industry',
                'employee_count',
                'contact_name',
                'contact_email',
                'is_active',
            ]);

        return Inertia::render('organizations/Index', [
            'organizations' => $organizations,
            'canManage' => Gate::allows('create', Organization::class),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Organization::class);

        return Inertia::render('organizations/Create');
    }

    public function show(
        Organization $organization,
        AssessmentProgress $assessmentProgress,
    ): Response
    {
        $this->ensureCustomer($organization);
        Gate::authorize('view', $organization);

        $projects = $organization->projects()
            ->with('assessment')
            ->orderByDesc('created_at')
            ->get([
                'id',
                'organization_id',
                'name',
                'framework',
                'approach',
                'bcm_level',
                'status',
                'started_at',
                'target_date',
                'completed_at',
            ])
            ->map(fn (IsmsProject $project): array => $this->assessmentProjectData(
                $project,
                $organization,
                $assessmentProgress,
            ));

        return Inertia::render('organizations/Show', [
            'organization' => $organization,
            'projects' => $projects,
            'canManage' => Gate::allows('update', $organization),
        ]);
    }

    public function edit(Organization $organization): Response
    {
        $this->ensureCustomer($organization);
        Gate::authorize('update', $organization);

        return Inertia::render('organizations/Edit', [
            'organization' => $organization,
        ]);
    }

    public function store(StoreOrganizationRequest $request, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('create', Organization::class);

        $data = $request->validated();
        $organization = Organization::query()->create([
            ...$data,
            'slug' => $this->uniqueSlug($data['name']),
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);

        $audit->record(
            'organization.created',
            $this->actor($request),
            ['changed_fields' => array_keys($data)],
            $organization->id,
        );

        return redirect('/organizations/'.$organization->id);
    }

    public function update(
        UpdateOrganizationRequest $request,
        Organization $organization,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->ensureCustomer($organization);
        Gate::authorize('update', $organization);

        $organization->fill($request->validated());
        $changedFields = array_keys($organization->getDirty());
        $organization->save();

        $audit->record(
            'organization.updated',
            $this->actor($request),
            ['changed_fields' => $changedFields],
            $organization->id,
        );

        return redirect('/organizations/'.$organization->id);
    }

    public function deactivate(
        Request $request,
        Organization $organization,
        AuditLogger $audit,
    ): RedirectResponse {
        $this->ensureCustomer($organization);
        Gate::authorize('deactivate', $organization);

        $organization->forceFill(['is_active' => false])->save();

        $audit->record(
            'organization.deactivated',
            $this->actor($request),
            ['changed_fields' => ['is_active']],
            $organization->id,
        );

        return redirect('/organizations/'.$organization->id);
    }

    private function ensureCustomer(Organization $organization): void
    {
        abort_unless($organization->organization_type === 'customer', 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function assessmentProjectData(
        IsmsProject $project,
        Organization $organization,
        AssessmentProgress $assessmentProgress,
    ): array {
        $project->setRelation('organization', $organization);
        $assessment = $project->assessment;
        $progress = $assessment instanceof ProjectAssessment
            ? $assessmentProgress->for($assessment)
            : null;

        return [
            ...$project->only([
                'id',
                'name',
                'framework',
                'approach',
                'bcm_level',
                'status',
                'started_at',
                'target_date',
                'completed_at',
            ]),
            'assessment_started' => $assessment instanceof ProjectAssessment,
            'assessment_url' => '/organizations/'.$organization->id.'/projects/'.$project->id.'/assessment',
            'assessment_progress' => $progress === null ? null : [
                'answered' => $progress['answered'],
                'total' => $progress['total'],
                'percentage' => $progress['percentage'],
            ],
            'can_assess' => Gate::allows('answerAssessment', $project),
        ];
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'organization';
        }

        $slug = $base;
        $suffix = 2;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
