<?php

namespace Tests\Feature\Imports;

use App\Enums\ProjectStatus;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Models\AuditEvent;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Imports\RegisterImportPreviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

class RegisterImportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_route_is_limited_to_writable_customer_projects_and_valid_kinds(): void
    {
        [$customer, $project, $actor] = $this->context();
        $response = $this->actingAs($actor)->post($this->previewUrl($customer, $project, 'processes'), [
            'file' => $this->upload('key,name,description,owner_name,owner_email,active'."\n".'P1,Process,,,,true'."\n"),
        ]);
        $response->assertRedirect();
        $this->assertDatabaseCount('register_import_batches', 1);

        $this->actingAs($actor)->post($this->previewUrl($customer, $project, 'other'), ['file' => $this->upload('x')])->assertNotFound();

        foreach ([ProjectStatus::Completed, ProjectStatus::Archived] as $status) {
            $project->update(['status' => $status]);
            $this->actingAs($actor)->post($this->previewUrl($customer, $project, 'processes'), ['file' => $this->upload('x')])->assertForbidden();
        }
        $project->update(['status' => ProjectStatus::Draft]);
        $customer->update(['is_active' => false]);
        $this->actingAs($actor)->post($this->previewUrl($customer, $project, 'processes'), ['file' => $this->upload('x')])->assertForbidden();
    }

    public function test_batch_show_requires_owner_and_exact_nested_scope_but_remains_readable_after_project_changes(): void
    {
        [$customer, $project, $actor] = $this->context();
        $batch = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            'summary' => ['counts' => ['new' => 1, 'changed' => 0, 'unchanged' => 0, 'invalid' => 0], 'rows' => []],
        ]);
        $otherActor = User::factory()->for($actor->organization)->create();

        $this->actingAs($otherActor)->get($this->showUrl($customer, $project, $batch))->assertForbidden();

        $otherProject = IsmsProject::factory()->for($customer)->create();
        $this->actingAs($actor)->get($this->showUrl($customer, $otherProject, $batch))->assertNotFound();
        $otherCustomer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $this->actingAs($actor)->get($this->showUrl($otherCustomer, $project, $batch))->assertNotFound();

        $project->update(['status' => ProjectStatus::Completed]);
        $customer->update(['is_active' => false]);
        $this->actingAs($actor)->get($this->showUrl($customer, $project, $batch))->assertOk()->assertJsonPath('canConfirm', false);
        $batch->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($actor)->get($this->showUrl($customer, $project, $batch))->assertOk()->assertJsonPath('expired', true);
    }

    public function test_customer_and_inactive_internal_users_cannot_access_imports(): void
    {
        [$customer, $project, $actor] = $this->context();
        $batch = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create();
        $customerUser = User::factory()->for($customer)->create();
        $this->actingAs($customerUser)->get($this->showUrl($customer, $project, $batch))->assertForbidden();
        $this->actingAs($customerUser)->post($this->previewUrl($customer, $project, 'processes'), ['file' => $this->upload('x')])->assertForbidden();

        $actor->update(['is_active' => false]);
        $this->actingAs($actor)->get($this->showUrl($customer, $project, $batch))->assertRedirect('/login');
    }

    public function test_preview_audit_is_customer_owned_redacted_and_failure_rolls_back_batch(): void
    {
        [$customer, $project, $actor] = $this->context();
        $secret = 'Private Process secret-owner@example.test';
        $valid = "key,name,description,owner_name,owner_email,active\nP1,{$secret},,,,true\n";
        app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::Processes, $this->upload($valid), $actor);
        $invalid = "key,name,type,description,owner_name,owner_email,active\nA1,Secret,invalid,,,,true\n";
        app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::Assets, $this->upload($invalid), $actor);

        $this->assertEqualsCanonicalizing(['register_import.previewed', 'register_import.rejected'], AuditEvent::query()->pluck('event_type')->all());
        $this->assertSame([$customer->id], AuditEvent::query()->distinct()->pluck('organization_id')->all());
        $auditJson = AuditEvent::query()->get()->toJson();
        $this->assertStringNotContainsString('Private Process', $auditJson);
        $this->assertStringNotContainsString('secret-owner@example.test', $auditJson);
        $this->assertStringNotContainsString(hash('sha256', $valid), $auditJson);
        $this->assertStringNotContainsString('invalid-type', $auditJson);

        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                throw new RuntimeException('audit unavailable');
            }
        });
        try {
            app(RegisterImportPreviewer::class)->preview($project, RegisterImportKind::Processes, $this->upload($valid), $actor);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('register_import_batches', 2);
    }

    /** @return array{Organization, IsmsProject, User} */
    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();

        return [$customer, $project, $actor];
    }

    private function previewUrl(Organization $organization, IsmsProject $project, string $kind): string
    {
        return "/organizations/{$organization->id}/projects/{$project->id}/imports/{$kind}/preview";
    }

    private function showUrl(Organization $organization, IsmsProject $project, RegisterImportBatch $batch): string
    {
        return "/organizations/{$organization->id}/projects/{$project->id}/imports/{$batch->id}";
    }

    private function upload(string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'register-import-');
        file_put_contents($path, $contents);

        return new UploadedFile($path, 'register.csv', 'text/csv', null, true);
    }
}
