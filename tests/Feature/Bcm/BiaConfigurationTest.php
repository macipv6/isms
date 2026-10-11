<?php

namespace Tests\Feature\Bcm;

use App\Enums\ProjectStatus;
use App\Models\BiaConfigurationVersion;
use App\Policies\ProjectBcmPolicy;
use App\Services\Bcm\BiaConfigurationWorkflow;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class BiaConfigurationTest extends TestCase
{
    use InteractsWithBcm, RefreshDatabase;

    public function test_defaults_without_bcms_flag(): void
    {
        [$project, $actor] = $this->bcmProject();
        $workflow = app(BiaConfigurationWorkflow::class);
        $this->assertNull($workflow->current($project));
        $this->assertDatabaseCount('audit_events', 0);
        $config = $workflow->initialize($project, $actor);
        $this->assertSame([60, 240, 480, 1440, 4320, 10080], $config->minutes);
        $this->assertSame($config->id, $workflow->initialize($project, $actor)->id);
        $this->assertSame($config->id, $workflow->current($project)->id);
        $this->assertSame(1, $project->fresh()->bia_configuration_lock_version);
        $this->assertDatabaseCount('bia_configuration_versions', 1);
        $this->assertDatabaseCount('audit_events', 1);
    }

    #[DataProvider('invalidMinutes')]
    public function test_invalid_times_do_not_write(array $minutes): void
    {
        [$project, $actor] = $this->bcmProject();
        try {
            app(BiaConfigurationWorkflow::class)->activate($project, $minutes, 0, $actor);
            $this->fail('Invalid minutes accepted');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('minutes', $exception->errors());
        }
        $this->assertDatabaseCount('bia_configuration_versions', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function invalidMinutes(): array
    {
        return [[[60, 60]], [[240, 60]], [[0, 60]], [[1.5, 60]], [range(1, 13)], [[60, 525601]], [[60]], [['60', 240]], [[1 => 60, 2 => 240]]];
    }

    public function test_activation_advances_head_and_rejects_stale_generation(): void
    {
        [$project, $actor] = $this->bcmProject();
        $workflow = app(BiaConfigurationWorkflow::class);
        $old = $workflow->initialize($project, $actor);
        $new = $workflow->activate($project, [1, 525600], 1, $actor);
        $this->assertSame(2, $new->version);
        $this->assertSame(2, $project->fresh()->bia_configuration_lock_version);
        $this->assertSame($new->id, $workflow->current($project)->id);
        $this->assertSame([60, 240, 480, 1440, 4320, 10080], $old->fresh()->minutes);
        try {
            $workflow->activate($project, [60, 240], 1, $actor);
            $this->fail('Stale generation accepted');
        } catch (ConflictHttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('bia_configuration_versions', 2);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_actor_is_reloaded_and_inactive_actor_cannot_initialize(): void
    {
        [$project, $actor] = $this->bcmProject();
        DB::table('users')->where('id', $actor->id)->update(['is_active' => false]);
        try {
            app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
            $this->fail('Inactive actor accepted');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('bia_configuration_versions', 0);
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public function test_completed_project_is_readable_but_not_writable(): void
    {
        [$project, $actor] = $this->bcmProject();
        $project->update(['status' => ProjectStatus::Completed]);
        $policy = app(ProjectBcmPolicy::class);
        $this->assertTrue($policy->view($actor, $project));
        $this->assertFalse($policy->write($actor, $project));
        $this->expectException(AuthorizationException::class);
        app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
    }

    #[DataProvider('historicalMutations')]
    public function test_published_configuration_rejects_direct_mutation(string $operation): void
    {
        [$project, $actor] = $this->bcmProject();
        $config = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $this->expectException(QueryException::class);
        if ($operation === 'delete') {
            BiaConfigurationVersion::query()->whereKey($config->id)->delete();
        } else {
            BiaConfigurationVersion::query()->whereKey($config->id)->update(['minutes' => [60, 120]]);
        }
    }

    public static function historicalMutations(): array
    {
        return [['update'], ['delete']];
    }

    public function test_head_rejects_foreign_project_configuration(): void
    {
        [$project, $actor] = $this->bcmProject();
        [$other] = $this->bcmProject();
        $config = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $this->expectException(QueryException::class);
        DB::table('isms_projects')->where('id', $other->id)->update(['bia_configuration_version_id' => $config->id, 'bia_configuration_lock_version' => 1]);
    }

    #[DataProvider('deniedWriters')]
    public function test_fresh_access_checks_prevent_unauthorized_writes(string $change): void
    {
        [$project, $actor] = $this->bcmProject();
        match ($change) {
            'customer_actor' => DB::table('users')->where('id', $actor->id)->update(['organization_id' => $project->organization_id]),
            'inactive_actor_organization' => DB::table('organizations')->where('id', $actor->organization_id)->update(['is_active' => false]),
            'inactive_customer' => DB::table('organizations')->where('id', $project->organization_id)->update(['is_active' => false]),
            'archived_project' => DB::table('isms_projects')->where('id', $project->id)->update(['status' => 'archived']),
        };
        try {
            app(BiaConfigurationWorkflow::class)->activate($project, [60, 240], 1, $actor);
            $this->fail('Unauthorized activation accepted');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('bia_configuration_versions', 0);
            $this->assertDatabaseCount('audit_events', 0);
        }
    }

    public static function deniedWriters(): array
    {
        return [['customer_actor'], ['inactive_actor_organization'], ['inactive_customer'], ['archived_project']];
    }

    #[DataProvider('invalidInserts')]
    public function test_database_enforces_published_version_invariants(string $change): void
    {
        [$project, $actor] = $this->bcmProject();
        $config = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $data = $config->getAttributes();
        $data['id'] = (string) Str::uuid();
        if ($change === 'fractional_minutes') {
            $data['version'] = 2;
            $data['minutes'] = '[1.5,60]';
        }
        $this->expectException(QueryException::class);
        DB::table('bia_configuration_versions')->insert($data);
    }

    public static function invalidInserts(): array
    {
        return [['duplicate_version'], ['fractional_minutes']];
    }
}
