<?php

namespace App\Http\Controllers;

use App\Http\BcmRouteScope;
use App\Models\BcmExercise;
use App\Models\ContinuityPlanRevision;
use App\Services\Bcm\BcmExercisePresenter;
use App\Services\Bcm\BcmExerciseWorkflow;
use App\Services\Bcm\BcmWorkspacePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class BcmExerciseController extends Controller
{
    public function index(Request $request, BcmExercisePresenter $presenter): Response
    {
        return app(BcmWorkspacePresenter::class)->render($request, 'bcm/Exercises', $presenter->index($request, BcmRouteScope::project($request, false)));
    }

    public function show(Request $request, BcmExercisePresenter $presenter): Response
    {
        $project = BcmRouteScope::project($request, false);

        return app(BcmWorkspacePresenter::class)->render($request, 'bcm/Exercises', $presenter->show($request, $project, BcmRouteScope::child($request, $project, 'exercise', BcmExercise::class)));
    }

    public function store(Request $request, BcmExerciseWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $plan = BcmRouteScope::inputChild($request, $project, 'plan_revision_id', ContinuityPlanRevision::class);
        $exercise = $workflow->create($plan, BcmRouteScope::payload($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.exercises.show', [$project->id, $exercise->id]);
    }

    public function update(Request $request, BcmExerciseWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $exercise = $workflow->save(BcmRouteScope::child($request, $project, 'exercise', BcmExercise::class), BcmRouteScope::payload($request), BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.exercises.show', [$project->id, $exercise->id]);
    }

    public function plan(Request $request, BcmExerciseWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $exercise = $workflow->plan(BcmRouteScope::child($request, $project, 'exercise', BcmExercise::class), BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.exercises.show', [$project->id, $exercise->id]);
    }

    public function record(Request $request, BcmExerciseWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $exercise = $workflow->record(BcmRouteScope::child($request, $project, 'exercise', BcmExercise::class), BcmRouteScope::payload($request), BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.exercises.show', [$project->id, $exercise->id]);
    }

    public function complete(Request $request, BcmExerciseWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $exercise = $workflow->complete(BcmRouteScope::child($request, $project, 'exercise', BcmExercise::class), BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.exercises.show', [$project->id, $exercise->id]);
    }

    public function cancel(Request $request, BcmExerciseWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:10000']]);
        $exercise = $workflow->cancel(BcmRouteScope::child($request, $project, 'exercise', BcmExercise::class), $data['reason'], BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.exercises.show', [$project->id, $exercise->id]);
    }
}
