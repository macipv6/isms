<?php

namespace Tests\Feature\Registers;

use App\Models\AuditEvent;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Registers\BusinessProcessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class RegisterAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_audits_are_customer_owned_redacted_and_skip_noop_status(): void
    {
        [$customer, $project, $actor] = $this->context();
        $service = app(BusinessProcessService::class);
        $process = $service->create($project, ['key' => 'SALES', 'name' => 'Secret name', 'owner_email' => 'secret@example.test', 'active' => true], $actor);
        $process = $service->update($process, ['name' => 'Other secret', 'description' => 'Private'], $actor, $process->updated_at->toIso8601String());
        $service->changeStatus($process, false, $actor);
        $service->changeStatus($process->fresh(), false, $actor);
        $this->assertDatabaseCount('audit_events', 3);
        $this->assertSame([$customer->id], AuditEvent::query()->distinct()->pluck('organization_id')->all());
        $updated = AuditEvent::query()->where('event_type', 'business_process.updated')->sole();
        $this->assertEqualsCanonicalizing(['name', 'description'], $updated->context['changed_fields']);
        $json = AuditEvent::query()->get()->toJson();
        foreach (['Secret name', 'Other secret', 'Private', 'secret@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_audit_failure_rolls_back_register_write(): void
    {
        [, $project, $actor] = $this->context();
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                throw new RuntimeException('audit unavailable');
            }
        });
        $this->expectException(RuntimeException::class);
        app(BusinessProcessService::class)->create($project, ['key' => 'SALES', 'name' => 'Sales', 'active' => true], $actor);
    }

    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();

        return [$customer, $project, $actor];
    }
}
