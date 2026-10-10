<?php

namespace App\Http\Controllers;

use App\Http\RiskRouteScope;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Policies\ProjectRiskPolicy;
use Illuminate\Http\Request;

abstract class RiskController extends Controller
{
    /** @return array{Organization, IsmsProject} */
    protected function scopedProject(Request $request, bool $write = true): array
    {
        [$organization, $project] = RiskRouteScope::project($request);
        $policy = app(ProjectRiskPolicy::class);
        abort_unless($write ? $policy->write($this->actor($request), $project) : $policy->view($this->actor($request), $project), 403);

        return [$organization, $project];
    }

    protected function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
