<?php

namespace Tests\Feature\Measures;

use App\Enums\MeasureStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MeasureAuthorizationTest extends TestCase
{
    use InteractsWithMeasures;
    use RefreshDatabase;

    public function test_active_internal_actor_can_create_update_and_transition_through_nested_routes(): void
    {
        [$organization, $project, $finding, $actor] = $this->measureContext();

        $this->actingAs($actor)
            ->post($this->measureStoreUrl($organization, $project, $finding), $this->measurePayload())
            ->assertRedirect()
            ->assertSessionHas('success', 'Maßnahme angelegt.');
        $measure = Measure::query()->where('finding_id', $finding->id)->sole();

        $this->actingAs($actor)
            ->put($this->measureUrl($organization, $project, $measure), $this->measurePayload(['title' => 'Aktualisiert']))
            ->assertRedirect()
            ->assertSessionHas('success', 'Maßnahme aktualisiert.');
        $this->actingAs($actor)
            ->patch($this->measureUrl($organization, $project, $measure, '/status'), ['status' => 'in_progress'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Maßnahmenstatus aktualisiert.');

        $this->assertSame(MeasureStatus::InProgress, $measure->fresh()->status);
    }

    public function test_guest_customer_and_inactive_internal_user_cannot_create(): void
    {
        [$organization, $project, $finding] = $this->measureContext();
        $url = $this->measureStoreUrl($organization, $project, $finding);

        $this->post($url, $this->measurePayload())->assertRedirect('/login');
        $customerUser = User::factory()->for($organization)->create(['role' => UserRole::Admin]);
        $this->actingAs($customerUser)->post($url, $this->measurePayload())->assertForbidden();
        $inactive = $this->internalMeasureUser(active: false);
        $this->actingAs($inactive)->post($url, $this->measurePayload())->assertRedirect('/login');
        $this->assertDatabaseCount('measures', 0);
    }

    #[DataProvider('readOnlyProjectStatuses')]
    public function test_read_only_project_and_inactive_customer_reject_measure_writes(string $status): void
    {
        [$organization, $project, $finding, $actor] = $this->measureContext(['status' => $status]);
        $this->actingAs($actor)
            ->post($this->measureStoreUrl($organization, $project, $finding), $this->measurePayload())
            ->assertForbidden();

        $project->update(['status' => ProjectStatus::Draft]);
        $organization->update(['is_active' => false]);
        $this->actingAs($actor)
            ->post($this->measureStoreUrl($organization, $project, $finding), $this->measurePayload())
            ->assertForbidden();
        $this->assertDatabaseCount('measures', 0);
    }

    public static function readOnlyProjectStatuses(): array
    {
        return [
            'completed' => [ProjectStatus::Completed->value],
            'archived' => [ProjectStatus::Archived->value],
        ];
    }

    public function test_nested_finding_and_measure_substitution_returns_404_before_writes(): void
    {
        [$organization, $project, $finding, $actor] = $this->measureContext();
        [$foreignOrganization, $foreignProject, $foreignFinding] = $this->measureContext();
        $foreignMeasure = Measure::factory()->for($foreignProject)->for($foreignFinding)->create();

        $this->actingAs($actor)
            ->post($this->measureStoreUrl($organization, $project, $foreignFinding), $this->measurePayload())
            ->assertNotFound();
        $this->actingAs($actor)
            ->put($this->measureUrl($organization, $project, $foreignMeasure), $this->measurePayload())
            ->assertNotFound();
        $this->actingAs($actor)
            ->patch($this->measureUrl($organization, $project, $foreignMeasure, '/status'), ['status' => 'in_progress'])
            ->assertNotFound();
        $this->actingAs($actor)
            ->post($this->measureStoreUrl($foreignOrganization, $project, $finding), $this->measurePayload())
            ->assertNotFound();
        $this->assertDatabaseCount('measures', 1);
    }

    public function test_http_validation_uses_schema_field_names_and_cancel_reason_rules(): void
    {
        [$organization, $project, $finding, $actor] = $this->measureContext();
        $url = $this->measureStoreUrl($organization, $project, $finding);

        $this->actingAs($actor)->post($url, [])->assertSessionHasErrors([
            'title', 'description', 'priority', 'responsible_name', 'due_date',
        ]);
        $this->actingAs($actor)->post($url, $this->measurePayload(['owner_name' => 'Falsches Feld', 'responsible_name' => null]))
            ->assertSessionHasErrors('responsible_name');
        $this->actingAs($actor)->post($url, $this->measurePayload());
        $measure = Measure::query()->where('finding_id', $finding->id)->sole();

        $this->actingAs($actor)
            ->patch($this->measureUrl($organization, $project, $measure, '/status'), ['status' => 'cancelled'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($actor)
            ->patch($this->measureUrl($organization, $project, $measure, '/status'), [
                'status' => 'cancelled',
                'reason' => str_repeat('a', 10001),
            ])
            ->assertSessionHasErrors('reason');
        $this->assertSame(MeasureStatus::Planned, $measure->fresh()->status);
    }
}
