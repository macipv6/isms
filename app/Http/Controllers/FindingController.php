<?php

namespace App\Http\Controllers;

use App\Enums\FindingStatus;
use App\Http\Requests\Findings\DecideFindingRequest;
use App\Http\Requests\Findings\StoreFindingRequest;
use App\Http\Requests\Findings\UpdateFindingRequest;
use App\Models\AssessmentQuestion;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Findings\FindingWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FindingController extends Controller
{
    public function store(
        StoreFindingRequest $request,
        Organization $organization,
        IsmsProject $project,
        AssessmentQuestion $question,
        FindingWorkflow $workflow,
    ): RedirectResponse {
        $workflow->propose($project, $question, $request->validated(), $this->actor($request));

        return back()->with('success', 'Feststellung vorgeschlagen.');
    }

    public function update(
        UpdateFindingRequest $request,
        Organization $organization,
        IsmsProject $project,
        Finding $finding,
        FindingWorkflow $workflow,
    ): RedirectResponse {
        $workflow->update($finding, $request->validated(), $this->actor($request));

        return back()->with('success', 'Feststellung aktualisiert.');
    }

    public function decide(
        DecideFindingRequest $request,
        Organization $organization,
        IsmsProject $project,
        Finding $finding,
        FindingWorkflow $workflow,
    ): RedirectResponse {
        $workflow->decide(
            $finding,
            FindingStatus::from($request->validated('status')),
            $request->validated('decision_note'),
            $this->actor($request),
        );

        return back()->with('success', 'Entscheidung gespeichert.');
    }

    public function close(
        Request $request,
        Organization $organization,
        IsmsProject $project,
        Finding $finding,
        FindingWorkflow $workflow,
    ): RedirectResponse {
        $this->findingOwnership($organization, $project, $finding);
        Gate::authorize('close', $finding);
        $workflow->close($finding, $this->actor($request));

        return back()->with('success', 'Feststellung geschlossen.');
    }

    private function findingOwnership(Organization $organization, IsmsProject $project, Finding $finding): void
    {
        abort_unless(
            $organization->organization_type === 'customer'
                && $project->organization_id === $organization->id
                && $finding->project_id === $project->id,
            404,
        );
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
