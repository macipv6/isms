<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organizations\StoreOrganizationRequest;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
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

    public function store(StoreOrganizationRequest $request): RedirectResponse
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

        return redirect('/organizations/'.$organization->id);
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): RedirectResponse
    {
        $this->ensureCustomer($organization);
        Gate::authorize('update', $organization);

        $organization->update($request->validated());

        return redirect('/organizations/'.$organization->id);
    }

    public function deactivate(Organization $organization): RedirectResponse
    {
        $this->ensureCustomer($organization);
        Gate::authorize('deactivate', $organization);

        $organization->forceFill(['is_active' => false])->save();

        return redirect('/organizations/'.$organization->id);
    }

    private function ensureCustomer(Organization $organization): void
    {
        abort_unless($organization->organization_type === 'customer', 404);
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
