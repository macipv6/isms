<?php

namespace Tests\Feature\Dependencies;

use App\Models\Asset;
use App\Models\AuditEvent;
use App\Models\BusinessProcess;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Dependencies\DependencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DependencyAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_dependency_audits_are_customer_owned_redacted_and_skip_noop_status(): void
    {
        [$customer, $project, $actor, $process, $asset] = $this->context();
        $service = app(DependencyService::class);
        $edge = $service->create($project, $this->payload('Initial secret reason'), $actor);
        $edge = $service->update($edge, ['importance' => 'critical', 'reason' => 'Changed secret reason'], $actor, $edge->updated_at->toIso8601String());
        $edge = $service->changeStatus($edge, false, $actor);
        $service->changeStatus($edge->fresh(), false, $actor);

        $this->assertDatabaseCount('audit_events', 3);
        $this->assertSame([$customer->id], AuditEvent::query()->distinct()->pluck('organization_id')->all());
        $created = AuditEvent::query()->where('event_type', 'dependency.created')->sole();
        $this->assertSame($process->id, $created->context['source_id']);
        $this->assertSame('PROC', $created->context['source_key']);
        $this->assertSame($asset->id, $created->context['target_id']);
        $updated = AuditEvent::query()->where('event_type', 'dependency.updated')->sole();
        $this->assertEqualsCanonicalizing(['importance', 'reason'], $updated->context['changed_fields']);
        $json = AuditEvent::query()->get()->toJson();
        foreach (['Initial secret reason', 'Changed secret reason', 'Process secret name', 'Asset secret name'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_audit_failure_rolls_back_dependency_write(): void
    {
        [, $project, $actor] = $this->context();
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(string $eventType, ?User $actor, array $context = [], ?string $organizationId = null): AuditEvent
            {
                throw new RuntimeException('audit unavailable');
            }
        });

        try {
            app(DependencyService::class)->create($project, $this->payload('Private reason'), $actor);
            $this->fail('Expected audit failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }

        $this->assertDatabaseCount('dependency_edges', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function context(): array
    {
        $customer = Organization::factory()->create(['organization_type' => 'customer', 'entra_tenant_id' => null]);
        $project = IsmsProject::factory()->for($customer)->create();
        $actor = User::factory()->for(Organization::factory()->state(['organization_type' => 'internal']))->create();
        $process = BusinessProcess::factory()->for($project, 'project')->create(['key' => 'PROC', 'name' => 'Process secret name']);
        $asset = Asset::factory()->for($project, 'project')->create(['key' => 'ASSET', 'name' => 'Asset secret name']);

        return [$customer, $project, $actor, $process, $asset];
    }

    private function payload(string $reason): array
    {
        return ['source_type' => 'process', 'source_key' => 'PROC', 'target_type' => 'asset', 'target_key' => 'ASSET', 'importance' => 'supporting', 'reason' => $reason];
    }
}
