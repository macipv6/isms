<?php

namespace Tests\Feature\Registers;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\DependencyEdge;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RegisterPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_and_asset_pages_are_scoped_filtered_paginated_and_allowlisted(): void
    {
        [$customer, $project, $actor] = $this->context();
        $process = BusinessProcess::factory()->for($project, 'project')->create([
            'key' => 'PROC-1', 'name' => 'Auftrag', 'owner_name' => 'Private Person',
            'owner_email' => 'owner@example.test', 'description' => 'Intern', 'created_by' => $actor->id,
        ]);
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'OLD-1', 'is_active' => false]);
        $asset = Asset::factory()->for($project, 'project')->create([
            'key' => 'APP-1', 'name' => 'CRM', 'type' => 'application',
            'owner_name' => 'Asset Owner', 'owner_email' => 'asset@example.test', 'created_by' => $actor->id,
        ]);
        Asset::factory()->for($project, 'project')->create(['key' => 'INFO-1', 'type' => 'information']);
        $foreign = IsmsProject::factory()->create();
        BusinessProcess::factory()->for($foreign, 'project')->create(['key' => 'FOREIGN']);
        Asset::factory()->for($foreign, 'project')->create(['key' => 'FOREIGN']);

        $this->actingAs($actor)->get($this->url($customer, $project, 'processes').'?key=PROC&state=active&edit='.$process->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('processes/Index')
                ->where('filters.key', 'PROC')
                ->where('filters.state', 'active')
                ->where('capabilities.create', true)
                ->where('capabilities.import', true)
                ->where('processes.meta.perPage', 25)
                ->has('processes.data', 1)
                ->where('processes.data.0.id', $process->id)
                ->where('processes.data.0.actions.update', $this->url($customer, $project, 'processes').'/'.$process->id)
                ->missing('processes.data.0.owner_email')
                ->missing('processes.data.0.created_by')
                ->where('editRecord.owner_email', 'owner@example.test')
                ->missing('editRecord.created_by'));

        $this->actingAs($actor)->get($this->url($customer, $project, 'assets').'?key=APP&state=active&type=application&edit='.$asset->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('assets/Index')
                ->where('filters.type', 'application')
                ->has('assets.data', 1)
                ->where('assets.data.0.id', $asset->id)
                ->missing('assets.data.0.owner_email')
                ->missing('assets.data.0.created_by')
                ->where('editRecord.owner_email', 'asset@example.test'));
    }

    public function test_register_page_filters_and_edit_substitution_are_validated_server_side(): void
    {
        [$customer, $project, $actor] = $this->context();
        $foreignAsset = Asset::factory()->create();
        $base = $this->url($customer, $project, 'assets');

        foreach ([['state=maybe', 'state'], ['type=device', 'type'], ['key[]=x', 'key']] as [$query, $field]) {
            $this->from($base)->actingAs($actor)->get($base.'?'.$query)->assertRedirect($base)->assertSessionHasErrors($field);
        }

        $this->actingAs($actor)->get($base.'?edit='.$foreignAsset->id)->assertNotFound();
    }

    public function test_register_pages_enforce_internal_roles_and_keep_history_read_only(): void
    {
        [$customer, $project, $actor] = $this->context(status: ProjectStatus::Completed);
        BusinessProcess::factory()->for($project, 'project')->create(['key' => 'HISTORY']);

        $this->actingAs($actor)->get($this->url($customer, $project, 'processes'))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->has('processes.data', 1)
                ->where('capabilities.create', false)
                ->where('capabilities.edit', false)
                ->where('capabilities.changeStatus', false)
                ->where('capabilities.import', false)
                ->missing('editRecord'));

        $customerUser = User::factory()->for($customer)->create(['role' => UserRole::Admin]);
        $this->actingAs($customerUser)->get($this->url($customer, $project, 'processes'))->assertForbidden();
        $otherCustomer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $this->actingAs($actor)->get($this->url($otherCustomer, $project, 'assets'))->assertNotFound();
    }

    public function test_pagination_links_keep_only_validated_allowlisted_query_parameters(): void
    {
        [$customer, $project, $actor] = $this->context();
        BusinessProcess::factory()->count(26)->for($project, 'project')->create();
        Asset::factory()->count(26)->for($project, 'project')->create();
        DependencyEdge::factory()->count(26)->for($project, 'project')->create();
        $secret = 'owner@example.test';

        foreach ([
            ['processes', 'processes.links.next'],
            ['assets', 'assets.links.next'],
            ['dependencies', 'dependencies.links.next'],
        ] as [$register, $linkProp]) {
            $this->actingAs($actor)
                ->get($this->url($customer, $project, $register).'?state=active&owner_email='.$secret.'&file=private.csv&raw_row=secret')
                ->assertOk()
                ->assertInertia(fn (Assert $page): Assert => $page
                    ->where($linkProp, fn (mixed $link): bool => is_string($link)
                        && str_contains($link, 'state=active')
                        && ! str_contains($link, 'owner_email')
                        && ! str_contains($link, 'private.csv')
                        && ! str_contains($link, 'raw_row')));
        }
    }

    /** @return array{Organization, IsmsProject, User} */
    private function context(ProjectStatus $status = ProjectStatus::Draft): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create(['status' => $status]);
        $internal = Organization::factory()->create(['organization_type' => 'internal']);
        $actor = User::factory()->for($internal)->create(['role' => UserRole::Consultant]);

        return [$customer, $project, $actor];
    }

    private function url(Organization $organization, IsmsProject $project, string $register): string
    {
        return "/organizations/{$organization->id}/projects/{$project->id}/{$register}";
    }
}
