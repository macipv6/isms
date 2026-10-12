<?php

namespace App\Http\Controllers;

use App\Http\BcmRouteScope;
use App\Models\BiaRevision;
use App\Services\Bcm\BcmConflictWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BcmConflictController extends Controller
{
    public function store(Request $request, BcmConflictWorkflow $workflow): RedirectResponse
    {
        $project = BcmRouteScope::project($request);
        $revision = BcmRouteScope::child($request, $project, 'revision', BiaRevision::class);
        $data = $request->validate(['fingerprint' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'max:10000']]);
        $workflow->accept($revision, $data['fingerprint'], $data['reason'], BcmRouteScope::version($request), BcmRouteScope::actor($request));

        return redirect()->route('bcm.bia.show', [$project->id, $revision->id]);
    }
}
