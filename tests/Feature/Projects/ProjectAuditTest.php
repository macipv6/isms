<?php

namespace Tests\Feature\Projects;

use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_creation_is_audited_without_free_text(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();

        $this->actingAs($admin)->post('/organizations/'.$customer->id.'/projects', [
            ...$this->payload(),
            'description' => 'Confidential project description.',
            'scope_text' => 'Sensitive scope content.',
        ])->assertRedirect();

        $project = IsmsProject::query()->sole();
        $event = AuditEvent::query()->where('event_type', 'project.created')->sole();
        $encodedContext = json_encode($event->context, JSON_THROW_ON_ERROR);

        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame($customer->id, $event->organization_id);
        $this->assertSame($project->id, $event->context['project_id']);
        $this->assertContains('scope_text', $event->context['changed_fields']);
        $this->assertStringNotContainsString('Sensitive scope content', $encodedContext);
        $this->assertStringNotContainsString('Confidential project description', $encodedContext);
    }

    public function test_project_update_audits_only_changed_field_names(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create([
            'created_by' => $admin->id,
            'name' => 'Alt',
            'scope_text' => 'Alter Scope',
        ]);

        $this->actingAs($admin)->put('/organizations/'.$customer->id.'/projects/'.$project->id, [
            ...$this->payload(),
            'name' => 'Neu',
            'scope_text' => 'Confidential new scope.',
        ])->assertRedirect();

        $event = AuditEvent::query()->where('event_type', 'project.updated')->sole();

        $this->assertSame($project->id, $event->context['project_id']);
        $this->assertEqualsCanonicalizing(['name', 'scope_text'], $event->context['changed_fields']);
        $this->assertStringNotContainsString('Confidential new scope', json_encode($event->context, JSON_THROW_ON_ERROR));
    }

    public function test_project_status_change_uses_dedicated_audit_event(): void
    {
        $admin = $this->admin();
        $customer = $this->customer();
        $project = IsmsProject::factory()->for($customer)->create([
            'created_by' => $admin->id,
            'name' => 'ISMS 2026',
            'description' => null,
            'scope_text' => 'Scope',
            'status' => ProjectStatus::Draft,
        ]);

        $this->actingAs($admin)->put('/organizations/'.$customer->id.'/projects/'.$project->id, [
            ...$this->payload(),
            'status' => ProjectStatus::Active->value,
        ])->assertRedirect();

        $event = AuditEvent::query()->where('event_type', 'project.status_changed')->sole();

        $this->assertSame($project->id, $event->context['project_id']);
        $this->assertSame(['status'], $event->context['changed_fields']);
    }

    /**
     * @return array<string, string|null>
     */
    private function payload(): array
    {
        return [
            'name' => 'ISMS 2026',
            'description' => null,
            'framework' => 'BSI',
            'approach' => 'basis_absicherung',
            'bcm_level' => 'aufbau_bcms',
            'status' => ProjectStatus::Draft->value,
            'scope_text' => 'Scope',
            'started_at' => null,
            'target_date' => null,
            'completed_at' => null,
        ];
    }

    private function admin(): User
    {
        $internal = Organization::factory()->create(['organization_type' => 'internal']);

        return User::factory()->for($internal)->create(['role' => UserRole::Admin]);
    }

    private function customer(): Organization
    {
        return Organization::factory()->create([
            'organization_type' => 'customer',
            'entra_tenant_id' => null,
            'is_active' => true,
        ]);
    }
}
