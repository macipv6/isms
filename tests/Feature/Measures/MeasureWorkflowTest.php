<?php

namespace Tests\Feature\Measures;

use App\Enums\FindingStatus;
use App\Enums\MeasurePriority;
use App\Enums\MeasureStatus;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Services\Measures\MeasureWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeasureWorkflowTest extends TestCase
{
    use InteractsWithMeasures;
    use RefreshDatabase;

    public function test_multiple_measures_are_created_for_an_accepted_finding_and_project_is_derived(): void
    {
        [$customer, $project, $finding, $actor] = $this->measureContext();
        $foreignProject = IsmsProject::factory()->create();
        $workflow = app(MeasureWorkflow::class);

        $first = $workflow->create($finding, $this->measurePayload(['project_id' => $foreignProject->id]), $actor);
        $second = $workflow->create($finding, $this->measurePayload(['title' => 'Zweite Maßnahme']), $actor);

        $this->assertSame($project->id, $first->project_id);
        $this->assertSame($finding->id, $first->finding_id);
        $this->assertSame(MeasureStatus::Planned, $first->status);
        $this->assertSame($actor->id, $first->created_by);
        $this->assertSame(2, $finding->measures()->count());
        $this->assertSame('Zweite Maßnahme', $second->title);
    }

    #[DataProvider('ineligibleFindingStatuses')]
    public function test_only_accepted_findings_receive_measures(string $status): void
    {
        [$customer, $project, $finding, $actor] = $this->measureContext();
        $finding->update(['status' => $status]);

        $this->expectValidationError(
            fn () => app(MeasureWorkflow::class)->create($finding->fresh(), $this->measurePayload(), $actor),
            'finding',
        );
        $this->assertDatabaseCount('measures', 0);
    }

    public static function ineligibleFindingStatuses(): array
    {
        return [
            'proposed' => [FindingStatus::Proposed->value],
            'rejected' => [FindingStatus::Rejected->value],
            'closed' => [FindingStatus::Closed->value],
        ];
    }

    #[DataProvider('invalidContent')]
    public function test_measure_content_is_bounded_and_field_specific(string $field, mixed $value): void
    {
        [$customer, $project, $finding, $actor] = $this->measureContext();

        $this->expectValidationError(
            fn () => app(MeasureWorkflow::class)->create($finding, $this->measurePayload([$field => $value]), $actor),
            $field,
        );
    }

    public static function invalidContent(): array
    {
        return [
            'title required' => ['title', ''],
            'title bounded' => ['title', str_repeat('a', 256)],
            'description required' => ['description', ''],
            'description bounded' => ['description', str_repeat('a', 10001)],
            'priority enum' => ['priority', 'urgent'],
            'responsible name required' => ['responsible_name', ''],
            'responsible name bounded' => ['responsible_name', str_repeat('a', 256)],
            'email valid' => ['responsible_email', 'not-an-email'],
            'email bounded' => ['responsible_email', str_repeat('a', 250).'@test.de'],
            'due date required' => ['due_date', ''],
            'due date ISO' => ['due_date', '31.01.2026'],
        ];
    }

    public function test_past_due_dates_are_allowed_and_nonterminal_content_can_be_updated(): void
    {
        [$customer, $project, $finding, $actor] = $this->measureContext();
        $workflow = app(MeasureWorkflow::class);
        $measure = $workflow->create($finding, $this->measurePayload(['due_date' => '2001-01-01']), $actor);

        $updated = $workflow->update($measure, $this->measurePayload([
            'title' => 'Überarbeitete Maßnahme',
            'priority' => MeasurePriority::Critical->value,
            'responsible_email' => null,
        ]), $actor);

        $this->assertSame('2001-01-01', $updated->due_date->format('Y-m-d'));
        $this->assertSame('Überarbeitete Maßnahme', $updated->title);
        $this->assertSame(MeasurePriority::Critical, $updated->priority);
        $this->assertNull($updated->responsible_email);
    }

    #[DataProvider('allowedTransitions')]
    public function test_allowed_status_edges_update_consistent_terminal_metadata(string $source, string $target): void
    {
        [$customer, $project, $finding, $actor] = $this->measureContext();
        $measure = Measure::factory()->for($project)->for($finding)->create(['status' => $source]);
        $reason = $target === MeasureStatus::Cancelled->value ? 'Nicht mehr erforderlich' : 'ignored secret';

        $transitioned = app(MeasureWorkflow::class)->transition($measure, MeasureStatus::from($target), $reason, $actor);

        $this->assertSame($target, $transitioned->status->value);
        if ($target === MeasureStatus::Completed->value) {
            $this->assertSame($actor->id, $transitioned->completed_by);
            $this->assertNotNull($transitioned->completed_at);
        } else {
            $this->assertNull($transitioned->completed_by);
            $this->assertNull($transitioned->completed_at);
        }
        $this->assertSame(
            $target === MeasureStatus::Cancelled->value ? 'Nicht mehr erforderlich' : null,
            $transitioned->cancelled_reason,
        );
    }

    public static function allowedTransitions(): array
    {
        return [
            'planned to in progress' => ['planned', 'in_progress'],
            'planned to cancelled' => ['planned', 'cancelled'],
            'in progress to blocked' => ['in_progress', 'blocked'],
            'in progress to completed' => ['in_progress', 'completed'],
            'in progress to cancelled' => ['in_progress', 'cancelled'],
            'blocked to in progress' => ['blocked', 'in_progress'],
            'blocked to cancelled' => ['blocked', 'cancelled'],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_every_other_status_edge_is_rejected(string $source, string $target): void
    {
        [$customer, $project, $finding, $actor] = $this->measureContext();
        $measure = Measure::factory()->for($project)->for($finding)->create(['status' => $source]);

        $this->expectValidationError(
            fn () => app(MeasureWorkflow::class)->transition($measure, MeasureStatus::from($target), 'Begründung', $actor),
            'status',
        );
    }

    public static function forbiddenTransitions(): array
    {
        return [
            'planned to planned' => ['planned', 'planned'],
            'planned to blocked' => ['planned', 'blocked'],
            'planned to completed' => ['planned', 'completed'],
            'in progress to planned' => ['in_progress', 'planned'],
            'in progress to in progress' => ['in_progress', 'in_progress'],
            'blocked to planned' => ['blocked', 'planned'],
            'blocked to blocked' => ['blocked', 'blocked'],
            'blocked to completed' => ['blocked', 'completed'],
            'completed to planned' => ['completed', 'planned'],
            'completed to in progress' => ['completed', 'in_progress'],
            'completed to blocked' => ['completed', 'blocked'],
            'completed to completed' => ['completed', 'completed'],
            'completed to cancelled' => ['completed', 'cancelled'],
            'cancelled to planned' => ['cancelled', 'planned'],
            'cancelled to in progress' => ['cancelled', 'in_progress'],
            'cancelled to blocked' => ['cancelled', 'blocked'],
            'cancelled to completed' => ['cancelled', 'completed'],
            'cancelled to cancelled' => ['cancelled', 'cancelled'],
        ];
    }

    public function test_cancellation_reason_and_terminal_or_stale_state_are_enforced(): void
    {
        [$customer, $project, $finding, $actor] = $this->measureContext();
        $workflow = app(MeasureWorkflow::class);
        $measure = Measure::factory()->for($project)->for($finding)->create();
        $stalePlannedMeasure = $measure->fresh();

        $this->expectValidationError(
            fn () => $workflow->transition($measure, MeasureStatus::Cancelled, null, $actor),
            'reason',
        );
        $measure->update(['status' => MeasureStatus::Completed, 'completed_by' => $actor->id, 'completed_at' => now()]);
        $this->expectValidationError(
            fn () => $workflow->transition($stalePlannedMeasure, MeasureStatus::InProgress, null, $actor),
            'status',
        );
        $this->expectValidationError(
            fn () => $workflow->update($measure->fresh(), $this->measurePayload(['title' => 'Verboten']), $actor),
            'measure',
        );
    }

    private function expectValidationError(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
