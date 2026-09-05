<?php

namespace App\Http\Controllers;

use App\Enums\MeasureStatus;
use App\Http\Requests\Measures\StoreMeasureRequest;
use App\Http\Requests\Measures\TransitionMeasureRequest;
use App\Http\Requests\Measures\UpdateMeasureRequest;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Measure;
use App\Models\Organization;
use App\Models\User;
use App\Services\Measures\MeasureWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MeasureController extends Controller
{
    public function store(
        StoreMeasureRequest $request,
        Organization $organization,
        IsmsProject $project,
        Finding $finding,
        MeasureWorkflow $workflow,
    ): RedirectResponse {
        $workflow->create($finding, $request->validated(), $this->actor($request));

        return back()->with('success', 'Maßnahme angelegt.');
    }

    public function update(
        UpdateMeasureRequest $request,
        Organization $organization,
        IsmsProject $project,
        Measure $measure,
        MeasureWorkflow $workflow,
    ): RedirectResponse {
        $workflow->update($measure, $request->validated(), $this->actor($request));

        return back()->with('success', 'Maßnahme aktualisiert.');
    }

    public function transition(
        TransitionMeasureRequest $request,
        Organization $organization,
        IsmsProject $project,
        Measure $measure,
        MeasureWorkflow $workflow,
    ): RedirectResponse {
        $workflow->transition(
            $measure,
            MeasureStatus::from($request->validated('status')),
            $request->validated('reason'),
            $this->actor($request),
        );

        return back()->with('success', 'Maßnahmenstatus aktualisiert.');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
