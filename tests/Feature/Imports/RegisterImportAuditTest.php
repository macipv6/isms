<?php

namespace Tests\Feature\Imports;

use App\Enums\RegisterImportKind;
use App\Models\AuditEvent;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Imports\RegisterImportConfirmer;
use App\Services\Imports\RegisterImportPreviewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class RegisterImportAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmation_emits_one_customer_owned_redacted_summary_event_without_row_audits(): void
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        BusinessProcess::factory()->for($project, 'project')->create([
            'key' => 'EXISTING', 'name' => 'Old secret', 'description' => null,
            'owner_name' => null, 'owner_email' => null, 'is_active' => true,
        ]);
        $contents = implode("\n", [
            'key,name,description,owner_name,owner_email,active',
            'NEW,Private new name,Secret description,Secret owner,secret@example.test,true',
            'EXISTING,Private changed name,,,,false',
        ])."\n";
        $path = tempnam(sys_get_temp_dir(), 'register-import-');
        file_put_contents($path, $contents);
        $batch = app(RegisterImportPreviewer::class)->preview(
            $project,
            RegisterImportKind::Processes,
            new UploadedFile($path, 'processes.csv', 'text/csv', null, true),
            $actor,
        );
        app(RegisterImportConfirmer::class)->confirm($batch, $actor);

        $this->assertSame(1, AuditEvent::query()->where('event_type', 'register_import.applied')->count());
        $event = AuditEvent::query()->where('event_type', 'register_import.applied')->sole();
        $this->assertSame('register_import.applied', $event->event_type);
        $this->assertSame($customer->id, $event->organization_id);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertEqualsCanonicalizing([
            'project_id' => $project->id,
            'import_batch_id' => $batch->id,
            'import_kind' => 'processes',
            'row_counts' => ['new' => 1, 'changed' => 1, 'unchanged' => 0, 'invalid' => 0],
        ], $event->context);
        $json = $event->toJson();
        foreach (['Private new name', 'Private changed name', 'Secret description', 'Secret owner', 'secret@example.test', hash('sha256', $contents), 'NEW', 'EXISTING'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'business_process.created']);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'business_process.updated']);
        $this->assertDatabaseMissing('audit_events', ['event_type' => 'business_process.status_changed']);
    }
}
