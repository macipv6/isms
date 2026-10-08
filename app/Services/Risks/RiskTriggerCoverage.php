<?php

namespace App\Services\Risks;

use App\Models\IsmsProject;
use App\Models\RiskAnalysisTrigger;
use Illuminate\Support\Facades\DB;

class RiskTriggerCoverage
{
    public function reopenIfUncovered(IsmsProject $project, string $triggerId): void
    {
        $stillCovered = DB::table('risk_analysis_trigger_links as links')
            ->join('risk_analysis_cycles as cycles', function ($join): void {
                $join->on('cycles.id', '=', 'links.risk_analysis_cycle_id')->on('cycles.project_id', '=', 'links.project_id');
            })
            ->where('links.project_id', $project->id)
            ->where('links.risk_analysis_trigger_id', $triggerId)
            ->where('cycles.is_current', true)
            ->where('cycles.needs_review', false)
            ->where('cycles.status', 'completed')
            ->exists();

        if (! $stillCovered) {
            RiskAnalysisTrigger::query()->whereKey($triggerId)->where('project_id', $project->id)->where('status', 'covered')->update(['status' => 'open']);
        }
    }
}
