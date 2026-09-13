<?php

namespace Tests\Feature\Imports;

use App\Enums\AssetType;
use App\Enums\RegisterImportKind;
use App\Enums\RegisterImportStatus;
use App\Models\Asset;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use App\Models\User;
use App\Services\Imports\RegisterImportConfirmer;
use App\Services\Imports\RegisterImportPreviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RegisterImportConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_confirmation_applies_exact_payload_once_without_touching_unchanged_or_omitted_rows(): void
    {
        Carbon::setTestNow('2026-09-13 10:00:00');
        [$customer, $project, $actor] = $this->context();
        $changed = BusinessProcess::factory()->for($project, 'project')->create([
            'key' => 'CHANGED', 'name' => 'Before', 'description' => null,
            'owner_name' => null, 'owner_email' => null, 'is_active' => false,
        ]);
        $unchanged = BusinessProcess::factory()->for($project, 'project')->create([
            'key' => 'UNCHANGED', 'name' => 'Unchanged', 'description' => null,
            'owner_name' => null, 'owner_email' => null, 'is_active' => true,
        ]);
        $omitted = BusinessProcess::factory()->for($project, 'project')->create([
            'key' => 'OMITTED', 'name' => 'Omitted', 'is_active' => true,
        ]);
        $unchangedAt = $unchanged->updated_at;
        $omittedAt = $omitted->updated_at;
        $batch = $this->preview($project, $actor, RegisterImportKind::Processes, implode("\n", [
            'key,name,description,owner_name,owner_email,active',
            'new,New process,New description,New owner,new@example.test,true',
            'changed,After,Changed description,Changed owner,changed@example.test,true',
            'unchanged,Unchanged,,,,true',
        ])."\n");

        Carbon::setTestNow('2026-09-13 10:05:00');
        $this->actingAs($actor)
            ->post($this->confirmUrl($customer, $project, $batch))
            ->assertRedirect($this->showUrl($customer, $project, $batch));

        $created = BusinessProcess::query()->where('project_id', $project->id)->where('key', 'NEW')->sole();
        $this->assertSame('New process', $created->name);
        $this->assertTrue($created->is_active);
        $changed->refresh();
        $this->assertSame('CHANGED', $changed->key);
        $this->assertSame('After', $changed->name);
        $this->assertTrue($changed->is_active);
        $this->assertTrue($unchanged->fresh()->updated_at->equalTo($unchangedAt));
        $this->assertTrue($omitted->fresh()->updated_at->equalTo($omittedAt));
        $batch->refresh();
        $this->assertSame(RegisterImportStatus::Applied, $batch->status);
        $this->assertTrue($batch->applied_at?->equalTo(now('UTC')) ?? false);

        $batch->update(['payload' => [[
            'key' => 'LATE', 'name' => 'Must not apply', 'description' => null,
            'owner_name' => null, 'owner_email' => null, 'active' => true,
        ]]]);
        $this->actingAs($actor)->post($this->confirmUrl($customer, $project, $batch))->assertForbidden();
        $this->assertDatabaseMissing('business_processes', ['project_id' => $project->id, 'key' => 'LATE']);
    }

    public function test_asset_confirmation_updates_type_and_deactivates_existing_asset_without_changing_key(): void
    {
        [, $project, $actor] = $this->context();
        $asset = Asset::factory()->for($project, 'project')->create([
            'key' => 'CRM', 'name' => 'Before', 'type' => AssetType::Application, 'is_active' => true,
        ]);
        $batch = $this->preview($project, $actor, RegisterImportKind::Assets, implode("\n", [
            'key,name,type,description,owner_name,owner_email,active',
            'crm,After,service,,,,false',
            'backup,Backup,it_system,,,,true',
        ])."\n");

        $confirmed = app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $asset->refresh();
        $this->assertSame('CRM', $asset->key);
        $this->assertSame('After', $asset->name);
        $this->assertSame(AssetType::Service, $asset->type);
        $this->assertFalse($asset->is_active);
        $this->assertDatabaseHas('assets', ['project_id' => $project->id, 'key' => 'BACKUP', 'is_active' => true]);
        $this->assertSame(RegisterImportStatus::Applied, $confirmed->status);
    }

    public function test_dependency_batches_cannot_be_sent_to_process_or_asset_confirmation(): void
    {
        [, $project, $actor] = $this->context();
        $batch = RegisterImportBatch::factory()->for($project, 'project')->for($actor, 'creator')->create([
            'kind' => RegisterImportKind::Dependencies,
            'payload' => [],
            'summary' => ['counts' => ['new' => 0, 'changed' => 0, 'unchanged' => 0, 'invalid' => 0], 'rows' => []],
        ]);

        try {
            app(RegisterImportConfirmer::class)->confirm($batch, $actor);
            $this->fail('Expected unsupported import kind rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('import', $exception->errors());
        }

        $this->assertSame(RegisterImportStatus::Pending, $batch->fresh()->status);
        $this->assertDatabaseCount('business_processes', 0);
        $this->assertDatabaseCount('assets', 0);
    }

    /** @return array{Organization, IsmsProject, User} */
    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();

        return [$customer, $project, $actor];
    }

    private function preview(IsmsProject $project, User $actor, RegisterImportKind $kind, string $contents): RegisterImportBatch
    {
        $path = tempnam(sys_get_temp_dir(), 'register-import-');
        file_put_contents($path, $contents);
        $file = new UploadedFile($path, 'register.csv', 'text/csv', null, true);

        return app(RegisterImportPreviewer::class)->preview($project, $kind, $file, $actor);
    }

    private function confirmUrl(Organization $organization, IsmsProject $project, RegisterImportBatch $batch): string
    {
        return "/organizations/{$organization->id}/projects/{$project->id}/imports/{$batch->id}/confirm";
    }

    private function showUrl(Organization $organization, IsmsProject $project, RegisterImportBatch $batch): string
    {
        return "/organizations/{$organization->id}/projects/{$project->id}/imports/{$batch->id}";
    }
}
