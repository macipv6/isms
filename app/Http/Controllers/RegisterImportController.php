<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\RegisterImportStatus;
use App\Http\Requests\Imports\ConfirmRegisterImportRequest;
use App\Http\Requests\Imports\PreviewRegisterImportRequest;
use App\Models\IsmsProject;
use App\Models\Organization;
use App\Models\RegisterImportBatch;
use App\Models\User;
use App\Services\Imports\RegisterImportConfirmer;
use App\Services\Imports\RegisterImportPreviewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RegisterImportController extends Controller
{
    public function preview(
        PreviewRegisterImportRequest $request,
        Organization $organization,
        IsmsProject $project,
        string $kind,
        RegisterImportPreviewer $previewer,
    ): RedirectResponse {
        $file = $request->file('file');
        abort_unless($file !== null, 422);
        $batch = $previewer->preview($project, $request->kind(), $file, $this->actor($request));

        return redirect()->route($this->indexRoute($batch), [$organization, $project, 'batch' => $batch->id]);
    }

    public function show(Request $request, Organization $organization, IsmsProject $project, RegisterImportBatch $batch): JsonResponse|RedirectResponse
    {
        abort_unless($organization->organization_type === 'customer' && $project->organization_id === $organization->id && $batch->project_id === $project->id, 404);
        Gate::authorize('view', $batch);
        if ($request->header('X-Inertia') !== null) {
            return redirect()->route($this->indexRoute($batch), [$organization, $project, 'batch' => $batch->id]);
        }
        $project->loadMissing('organization');
        $expired = $batch->expires_at->getTimestamp() <= now('UTC')->getTimestamp();
        $canConfirm = $batch->status === RegisterImportStatus::Pending
            && ! $expired
            && $project->organization->is_active
            && in_array($project->status, [ProjectStatus::Draft, ProjectStatus::Active], true);

        return response()->json([
            'id' => $batch->id,
            'kind' => $batch->kind->value,
            'status' => $batch->status->value,
            'summary' => $batch->summary,
            'expiresAt' => $batch->expires_at->toIso8601String(),
            'expired' => $expired,
            'canConfirm' => $canConfirm,
        ]);
    }

    public function confirm(
        ConfirmRegisterImportRequest $request,
        Organization $organization,
        IsmsProject $project,
        RegisterImportBatch $batch,
        RegisterImportConfirmer $confirmer,
    ): RedirectResponse {
        $confirmed = $confirmer->confirm($batch, $this->actor($request));

        return redirect()->route('register-imports.show', [$organization, $project, $confirmed]);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function indexRoute(RegisterImportBatch $batch): string
    {
        return match ($batch->kind->value) {
            'processes' => 'processes.index',
            'assets' => 'assets.index',
            'dependencies' => 'dependencies.index',
        };
    }
}
