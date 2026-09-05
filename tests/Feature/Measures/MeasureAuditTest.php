<?php

namespace Tests\Feature\Measures;

use App\Enums\MeasureStatus;
use App\Models\AuditEvent;
use App\Models\Measure;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Measures\MeasureWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MeasureAuditTest extends TestCase
{
    use InteractsWithMeasures;
    use RefreshDatabase;

    public function test_create_update_and_transition_are_audited_once_for_customer_without_sensitive_content(): void
    {
        [$customer, $project, $finding, $actor] = $this->measureContext();
        $workflow = app(MeasureWorkflow::class);
        $measure = $workflow->create($finding, $this->measurePayload(), $actor);
        $measure = $workflow->update($measure, $this->measurePayload([
            'title' => 'Geheimer Maßnahmentitel',
            'description' => 'Geheime Beschreibung',
            'responsible_name' => 'Geheime Person',
            'responsible_email' => 'secret@example.test',
        ]), $actor);
        $workflow->update($measure, $this->measurePayload([
            'title' => 'Geheimer Maßnahmentitel',
            'description' => 'Geheime Beschreibung',
            'responsible_name' => 'Geheime Person',
            'responsible_email' => 'secret@example.test',
        ]), $actor);
        $workflow->transition($measure, MeasureStatus::Cancelled, 'Geheimer Abbruchgrund', $actor);

        $this->assertDatabaseCount('audit_events', 3);
        foreach (['measure.created', 'measure.updated', 'measure.status_changed'] as $eventType) {
            $this->assertSame(1, AuditEvent::query()->where('event_type', $eventType)->count());
        }
        $this->assertSame([$customer->id], AuditEvent::query()->distinct()->pluck('organization_id')->all());
        $updated = AuditEvent::query()->where('event_type', 'measure.updated')->sole();
        $this->assertEqualsCanonicalizing(
            ['title', 'description', 'responsible_name', 'responsible_email'],
            $updated->context['changed_fields'],
        );
        $encoded = AuditEvent::query()->get()->toJson();
        foreach (['Geheimer Maßnahmentitel', 'Geheime Beschreibung', 'Geheime Person', 'secret@example.test', 'Geheimer Abbruchgrund'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
    }

    public function test_audit_failure_rolls_back_create_update_and_transition(): void
    {
        [$customer, $project, $finding, $actor] = $this->measureContext();
        $measure = Measure::factory()->for($project)->for($finding)->create();
        $originalTitle = $measure->title;
        $this->app->instance(AuditLogger::class, $this->failingAuditLogger());

        $this->expectAuditFailure(fn () => app(MeasureWorkflow::class)->create($finding, $this->measurePayload(), $actor));
        $this->assertDatabaseCount('measures', 1);
        $this->expectAuditFailure(fn () => app(MeasureWorkflow::class)->update($measure, $this->measurePayload(['title' => 'Rollback-Titel']), $actor));
        $this->assertSame($originalTitle, $measure->fresh()->title);
        $this->expectAuditFailure(fn () => app(MeasureWorkflow::class)->transition($measure->fresh(), MeasureStatus::InProgress, null, $actor));
        $this->assertSame(MeasureStatus::Planned, $measure->fresh()->status);
    }

    private function expectAuditFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the audit failure to bubble out.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
    }

    private function failingAuditLogger(): AuditLogger
    {
        return new class extends AuditLogger
        {
            public function record(
                string $eventType,
                ?User $actor,
                array $context = [],
                ?string $organizationId = null,
            ): AuditEvent {
                throw new RuntimeException('audit unavailable');
            }
        };
    }
}
