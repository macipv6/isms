<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\User;
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
        ]);
    }

    public function show(Organization $organization): Response
    {
        $this->ensureCustomer($organization);
        Gate::authorize('view', $organization);

        return Inertia::render('organizations/Show', [
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
