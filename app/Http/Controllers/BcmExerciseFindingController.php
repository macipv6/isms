<?php

namespace App\Http\Controllers;

use App\Http\BcmRouteScope;
use App\Models\BcmExerciseResult;
use App\Services\Bcm\BcmExerciseWorkflow;
use App\Services\Findings\FindingWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BcmExerciseFindingController extends Controller
{
    public function store(Request $request, FindingWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $result = BcmRouteScope::child($request, $project, 'result', BcmExerciseResult::class);
        $workflow->proposeFromExercise($result, $request->all(), BcmRouteScope::actor($request));

        return redirect()->route('bcm.exercises.show', [$project->id, $result->bcm_exercise_id]);
    }

    public function waive(Request $request, BcmExerciseWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $result = BcmRouteScope::child($request, $project, 'result', BcmExerciseResult::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:10000']]);
        $workflow->waiveFinding($result, $data['reason'], BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.exercises.show', [$project->id, $result->bcm_exercise_id]);
    }
}
