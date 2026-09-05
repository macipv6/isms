<?php

namespace App\Http\Controllers;

use App\Enums\EvidenceReviewStatus;
use App\Exceptions\EvidenceIntegrityException;
use App\Http\Requests\Evidence\ReviewEvidenceRequest;
use App\Http\Requests\Evidence\StoreEvidenceRequest;
use App\Models\AssessmentQuestion;
use App\Models\EvidenceFile;
use App\Models\Finding;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\User;
use App\Services\Evidence\EvidenceDownloadService;
use App\Services\Evidence\EvidenceLinkService;
use App\Services\Evidence\EvidenceReviewService;
use App\Services\Evidence\EvidenceUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EvidenceController extends Controller
{
    public function store(
        StoreEvidenceRequest $request,
        Organization $organization,
        IsmsProject $project,
        AssessmentQuestion $question,
        EvidenceUploadService $uploads,
    ): RedirectResponse {
        $actor = $this->actor($request);
        $uploads->uploadForQuestion($project, $question, $request->file('file'), $actor);

        return back()->with('success', 'Nachweis hochgeladen.');
    }

    public function linkQuestion(
        Request $request,
        Organization $organization,
        IsmsProject $project,
        EvidenceFile $evidence,
        AssessmentQuestion $question,
        EvidenceLinkService $links,
    ): RedirectResponse {
        $this->questionOwnership($organization, $project, $question);
        $this->evidenceOwnership($project, $evidence);
        Gate::authorize('link', [EvidenceFile::class, $project]);
        $links->linkToQuestion($project, $evidence, $question, $this->actor($request));

        return back()->with('success', 'Nachweis verknüpft.');
    }

    public function linkFinding(
        Request $request,
        Organization $organization,
        IsmsProject $project,
        Finding $finding,
        EvidenceFile $evidence,
        EvidenceLinkService $links,
    ): RedirectResponse {
        $this->projectOwnership($organization, $project);
        $this->evidenceOwnership($project, $evidence);
        abort_unless($finding->project_id === $project->id, 404);
        Gate::authorize('link', [EvidenceFile::class, $project]);
        $links->linkToFinding($project, $evidence, $finding, $this->actor($request));

        return back()->with('success', 'Nachweis verknüpft.');
    }

    public function review(
        ReviewEvidenceRequest $request,
        Organization $organization,
        IsmsProject $project,
        EvidenceFile $evidence,
        EvidenceReviewService $reviews,
    ): RedirectResponse {
        $reviews->review($evidence, EvidenceReviewStatus::from($request->validated('status')), $request->validated('review_note'), $this->actor($request));

        return back()->with('success', 'Prüfung gespeichert.');
    }

    public function download(
        Request $request,
        Organization $organization,
        IsmsProject $project,
        EvidenceFile $evidence,
        EvidenceDownloadService $downloads,
    ): StreamedResponse {
        $this->projectOwnership($organization, $project);
        $this->evidenceOwnership($project, $evidence);
        Gate::authorize('view', $evidence);

        try {
            return $downloads->download($evidence);
        } catch (EvidenceIntegrityException) {
            abort(422, 'Der Nachweis konnte nicht sicher bereitgestellt werden.');
        }
    }

    private function projectOwnership(Organization $organization, IsmsProject $project): void
    {
        abort_unless($organization->organization_type === 'customer' && $project->organization_id === $organization->id, 404);
    }

    private function evidenceOwnership(IsmsProject $project, EvidenceFile $evidence): void
    {
        abort_unless($evidence->project_id === $project->id, 404);
    }

    private function questionOwnership(Organization $organization, IsmsProject $project, AssessmentQuestion $question): void
    {
        $this->projectOwnership($organization, $project);
        $assessment = $project->assessment()->first();
        abort_unless($assessment !== null && $question->project_assessment_id === $assessment->id, 404);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
