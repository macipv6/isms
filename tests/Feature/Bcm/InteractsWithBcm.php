<?php

namespace Tests\Feature\Bcm;

use App\Models\BiaConfigurationVersion;
use App\Models\BusinessProcess;
use App\Models\ContinuityPlanRevision;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Bcm\BiaConfigurationWorkflow;
use App\Services\Bcm\BiaWorkflow;
use App\Services\Bcm\ContinuityPlanWorkflow;

trait InteractsWithBcm
{
    protected function approvedPlan(IsmsProject $project, User $actor): ContinuityPlanRevision
    {
        $process = BusinessProcess::factory()->create(['project_id' => $project->id]);
        $configuration = app(BiaConfigurationWorkflow::class)->initialize($project, $actor);
        $bia = app(BiaWorkflow::class);
        $bia->approve($bia->save($bia->draft($process, $actor), $this->completeBiaData($configuration), 1, $actor), 2, $actor);
        $workflow = app(ContinuityPlanWorkflow::class);
        $revision = $workflow->create($project, ['title' => 'Recovery plan', 'scenario' => 'Service outage', 'activation_conditions' => 'Service unavailable', 'strategy' => 'Restore service', 'minimum_operation' => 'Manual operation', 'normal_operation_return' => 'Validate restoration', 'process_ids' => [$process->id], 'steps' => [['instruction' => 'Restore service', 'owner_name' => 'Operator']], 'roles' => [['role' => 'Lead', 'name' => 'Leader', 'deputy_name' => 'Deputy']]], $actor);

        return $workflow->approve($revision, 1, $actor);
    }

    protected function completeBiaData(BiaConfigurationVersion $configuration): array
    {
        $impacts = [];
        foreach (['financial', 'operational', 'legal', 'reputation'] as $area) {
            foreach ($configuration->minutes as $minute) {
                $impacts[] = ['area' => $area, 'minutes' => $minute, 'level' => 2, 'reason' => 'Reviewed impact'];
            }
        }

        return ['owner_name' => 'Process owner', 'mtpd_minutes' => 600, 'rto_minutes' => 120,
            'rpo_minutes' => 0, 'rpo_not_applicable' => false, 'rpo_reason' => null,
            'target_reason' => 'Restore before maximum outage', 'impacts' => $impacts];
    }

    /** @return array{IsmsProject, User} */
    protected function bcmProject(bool $withBcms = false): array
    {
        return [
            IsmsProject::factory()->create(['bcm_level' => $withBcms ? 'aufbau_bcms' : 'kein_bcms']),
            User::factory()->for(Organization::factory()->create(['organization_type' => 'internal', 'is_active' => true]))->create(['is_active' => true]),
        ];
    }
}
